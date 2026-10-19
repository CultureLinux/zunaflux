<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SubscriptionControllerTest extends WebTestCase
{
    public function testTheSubscriptionManagementPageRequiresAuthentication(): void
    {
        $client = static::createClient();
        $client->request('GET', '/subscriptions');

        self::assertResponseRedirects('/login');
    }

    public function testAnAuthenticatedUserCanSubscribeToAFeed(): void
    {
        $client = static::createClient();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $email = 'subscription-test-'.bin2hex(random_bytes(8)).'@example.test';
        $feedUrl = 'https://example.test/'.bin2hex(random_bytes(8)).'/feed.xml';
        $user = new User($email);
        $entityManager->persist($user);
        $entityManager->flush();

        try {
            $client->loginUser($user);
            $crawler = $client->request('GET', '/subscriptions');
            $client->submit($crawler->selectButton('Ajouter le flux')->form(), [
                'feed_url' => $feedUrl,
            ]);

            self::assertResponseRedirects('/subscriptions');
            self::assertSame(1, (int) $entityManager->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM subscription WHERE user_id = ?',
                [$user->getId()->toBinary()],
            ));
        } finally {
            $entityManager->getConnection()->executeStatement('DELETE FROM app_user WHERE id = ?', [$user->getId()->toBinary()]);
            $entityManager->getConnection()->executeStatement('DELETE FROM feed WHERE canonical_url = ?', [$feedUrl]);
        }
    }

    public function testAnAuthenticatedUserCanCreateAPersonalCategory(): void
    {
        $client = static::createClient();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $user = new User('category-test-'.bin2hex(random_bytes(8)).'@example.test');
        $entityManager->persist($user);
        $entityManager->flush();

        try {
            $client->loginUser($user);
            $crawler = $client->request('GET', '/subscriptions');
            $client->submit($crawler->selectButton('Créer la catégorie')->form(), [
                'category_name' => 'Veille',
            ]);

            self::assertResponseRedirects('/subscriptions');
            self::assertSame(1, (int) $entityManager->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM category WHERE user_id = ? AND name = ?',
                [$user->getId()->toBinary(), 'Veille'],
            ));
        } finally {
            $entityManager->getConnection()->executeStatement('DELETE FROM app_user WHERE id = ?', [$user->getId()->toBinary()]);
        }
    }
}
