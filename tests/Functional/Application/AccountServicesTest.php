<?php

declare(strict_types=1);

namespace App\Tests\Functional\Application;

use App\Application\Administration\DeleteUserAccount;
use App\Application\Installation\CreateInitialAdministrator;
use App\Entity\ArticleState;
use App\Entity\User;
use App\Tests\Support\RollbackKernelTestCase;

final class AccountServicesTest extends RollbackKernelTestCase
{
    public function testAnActiveAccountCannotBeDeleted(): void
    {
        $user = $this->user();
        $this->em->flush();

        $this->expectException(\DomainException::class);
        try {
            self::getContainer()->get(DeleteUserAccount::class)->delete($user);
        } finally {
            self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM app_user WHERE id = ?', [$user->getId()->toBinary()]));
        }
    }

    public function testADisabledAccountIsDeletedWithItsPrivateData(): void
    {
        $user = $this->user();
        $bystander = $this->user();
        $feed = $this->feed();
        $article = $this->article($feed);
        $this->subscribe($user, $feed);
        $this->subscribe($bystander, $feed);
        $state = new ArticleState($user, $article);
        $state->setFavorite(true);
        $this->em->persist($state);
        $this->em->persist(new ArticleState($bystander, $article));
        $user->disable();
        $this->em->flush();

        self::getContainer()->get(DeleteUserAccount::class)->delete($user);

        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM app_user WHERE id = ?', [$user->getId()->toBinary()]));
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM subscription WHERE user_id = ?', [$user->getId()->toBinary()]));
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM article_state WHERE user_id = ?', [$user->getId()->toBinary()]));
        // Shared feeds, articles and other accounts survive.
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM feed WHERE id = ?', [$feed->getId()->toBinary()]));
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM article WHERE id = ?', [$article->getId()->toBinary()]));
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM subscription WHERE user_id = ?', [$bystander->getId()->toBinary()]));
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM article_state WHERE user_id = ?', [$bystander->getId()->toBinary()]));
    }

    public function testTheInitialAdministratorIsCreatedOnlyOnAnEmptyInstallation(): void
    {
        $this->db->executeStatement('DELETE FROM app_user');
        $service = self::getContainer()->get(CreateInitialAdministrator::class);

        self::assertTrue($service->isRequired());
        self::assertTrue($service->create('first-admin@example.test', 'a-secure-password'));
        self::assertFalse($service->isRequired());
        self::assertFalse($service->create('second-admin@example.test', 'a-secure-password'));

        $this->em->clear();
        $admin = $this->em->getRepository(User::class)->findOneBy(['emailNormalized' => 'first-admin@example.test']);
        self::assertInstanceOf(User::class, $admin);
        self::assertTrue($admin->isAdmin());
        self::assertTrue(self::getContainer()->get('security.user_password_hasher')->isPasswordValid($admin, 'a-secure-password'));
        self::assertNull($this->em->getRepository(User::class)->findOneBy(['emailNormalized' => 'second-admin@example.test']));
    }
}
