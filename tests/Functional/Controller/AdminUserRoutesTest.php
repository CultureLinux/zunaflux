<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\User;
use App\Tests\Support\CsrfTokens;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminUserRoutesTest extends WebTestCase
{
    use CsrfTokens;

    /** @var list<User> */
    private array $users = [];

    protected function tearDown(): void
    {
        $db = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        foreach ($this->users as $user) {
            $db->executeStatement('DELETE FROM app_user WHERE id = ?', [$user->getId()->toBinary()]);
        }
        parent::tearDown();
    }

    public function testOrdinaryUsersCannotUseAnyAdministrationAction(): void
    {
        $client = static::createClient();
        $member = $this->createUser();
        $target = $this->createUser();
        $client->loginUser($member);

        foreach (['', '/impersonate', '/delete'] as $suffix) {
            $client->request('POST', '/admin/users/'.$target->getId().$suffix, ['_token' => 'x', 'confirmation' => 'SUPPRIMER']);
            self::assertResponseStatusCodeSame(403, 'POST /admin/users/{id}'.$suffix);
        }
        $client->request('POST', '/admin/users', ['_token' => 'x', 'email' => 'x@example.test', 'password' => 'a-secure-password']);
        self::assertResponseStatusCodeSame(403);
        self::assertTrue($this->fresh($target)->isActive());
    }

    public function testAnAdministratorDisablesAndReEnablesAnAccountAndTheSessionEnds(): void
    {
        $client = static::createClient();
        $admin = $this->createUser(admin: true);
        $target = $this->createUser();
        $client->loginUser($admin);

        $this->post($client, 'admin-user-'.$target->getId(), '/admin/users/'.$target->getId(), ['admin' => '0', 'subscription_limit' => '5']);
        self::assertResponseRedirects('/admin');
        $disabled = $this->fresh($target);
        self::assertFalse($disabled->isActive());
        self::assertSame(5, $disabled->getSubscriptionLimit());

        $this->post($client, 'admin-user-'.$target->getId(), '/admin/users/'.$target->getId(), ['enabled' => '1', 'admin' => '1', 'subscription_limit' => '']);
        $enabled = $this->fresh($target);
        self::assertTrue($enabled->isActive());
        self::assertTrue($enabled->isAdmin());
        self::assertNull($enabled->getSubscriptionLimit());
    }

    public function testTheLastActiveAdministratorCannotBeDemotedOrDisabled(): void
    {
        $client = static::createClient();
        $admin = $this->createUser(admin: true);
        $others = (int) static::getContainer()->get(EntityManagerInterface::class)->getConnection()->fetchOne("SELECT COUNT(*) FROM app_user WHERE roles LIKE '%ROLE_ADMIN%' AND status = 'active' AND id != ?", [$admin->getId()->toBinary()]);
        if (0 !== $others) {
            self::markTestSkipped('Another active administrator exists in this database.');
        }
        $client->loginUser($admin);

        $this->post($client, 'admin-user-'.$admin->getId(), '/admin/users/'.$admin->getId(), ['enabled' => '1', 'admin' => '0']);
        self::assertResponseRedirects('/admin');
        $client->followRedirect();
        self::assertSelectorTextContains('.toast', 'dernier administrateur actif');
        self::assertTrue($this->fresh($admin)->isAdmin());

        $this->post($client, 'admin-user-'.$admin->getId(), '/admin/users/'.$admin->getId(), ['admin' => '1']);
        self::assertTrue($this->fresh($admin)->isActive());
    }

    public function testImpersonationIsRestrictedToOtherActiveAccounts(): void
    {
        $client = static::createClient();
        $admin = $this->createUser(admin: true);
        $target = $this->createUser();
        $inactive = $this->createUser();
        $inactive->disable();
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $client->loginUser($admin);

        $this->post($client, 'impersonate-user-'.$target->getId(), '/admin/users/'.$target->getId().'/impersonate');
        self::assertResponseRedirects();
        self::assertStringContainsString('_switch_user='.$target->getUserIdentifier(), (string) $client->getResponse()->headers->get('Location'));

        $this->post($client, 'impersonate-user-'.$admin->getId(), '/admin/users/'.$admin->getId().'/impersonate');
        self::assertResponseStatusCodeSame(403);
        $this->post($client, 'impersonate-user-'.$inactive->getId(), '/admin/users/'.$inactive->getId().'/impersonate');
        self::assertResponseStatusCodeSame(404);
        $client->request('POST', '/admin/users/'.$target->getId().'/impersonate', ['_token' => 'forged']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testDeletionNeedsADisabledAccountTheConfirmationWordAndAValidToken(): void
    {
        $client = static::createClient();
        $admin = $this->createUser(admin: true);
        $active = $this->createUser();
        $disabled = $this->createUser();
        $disabled->disable();
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $client->loginUser($admin);

        $this->post($client, 'delete-user-'.$active->getId(), '/admin/users/'.$active->getId().'/delete', ['confirmation' => 'SUPPRIMER']);
        self::assertResponseRedirects('/admin');
        self::assertNotNull($this->find($active), 'An active account must survive.');

        $this->post($client, 'delete-user-'.$disabled->getId(), '/admin/users/'.$disabled->getId().'/delete', ['confirmation' => 'oui']);
        self::assertResponseStatusCodeSame(403);
        $client->request('POST', '/admin/users/'.$disabled->getId().'/delete', ['_token' => 'forged', 'confirmation' => 'SUPPRIMER']);
        self::assertResponseStatusCodeSame(403);
        self::assertNotNull($this->find($disabled));

        $this->post($client, 'delete-user-'.$disabled->getId(), '/admin/users/'.$disabled->getId().'/delete', ['confirmation' => 'SUPPRIMER']);
        self::assertResponseRedirects('/admin');
        self::assertNull($this->find($disabled));

        $this->post($client, 'delete-user-'.$admin->getId(), '/admin/users/'.$admin->getId().'/delete', ['confirmation' => 'SUPPRIMER']);
        self::assertResponseStatusCodeSame(403);
        $client->request('POST', '/admin/users/not-a-uuid/delete', ['_token' => 'x', 'confirmation' => 'SUPPRIMER']);
        self::assertResponseStatusCodeSame(404);
    }

    public function testAccountCreationReportsAMissingPasswordWithoutLeakingInternals(): void
    {
        $client = static::createClient();
        $admin = $this->createUser(admin: true);
        $client->loginUser($admin);

        $this->post($client, 'admin-user-create', '/admin/users', ['email' => 'short-'.bin2hex(random_bytes(4)).'@example.test', 'password' => 'short']);
        self::assertResponseRedirects('/admin');
        $client->followRedirect();
        self::assertSelectorTextContains('.toast', '12 caractères');
    }

    /** @param array<string, string> $fields */
    private function post(KernelBrowser $client, string $tokenId, string $url, array $fields = []): void
    {
        $client->request('POST', $url, ['_token' => $this->csrfToken($client, $tokenId)] + $fields);
    }

    private function createUser(bool $admin = false): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = new User(($admin ? 'admin' : 'member').'-'.bin2hex(random_bytes(6)).'@example.test');
        if ($admin) {
            $user->addRole('ROLE_ADMIN');
        }
        $em->persist($user);
        $em->flush();
        $this->users[] = $user;

        return $user;
    }

    private function find(User $user): ?User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        return $em->getRepository(User::class)->find($user->getId());
    }

    private function fresh(User $user): User
    {
        return $this->find($user) ?? throw new \LogicException('The account no longer exists.');
    }
}
