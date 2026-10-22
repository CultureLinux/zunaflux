<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PushNotificationTest extends WebTestCase
{
    public function testOnlyAdministratorsCanRequestATestNotification(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = new User('push-test-'.bin2hex(random_bytes(8)).'@example.test');
        $em->persist($user);
        $em->flush();
        try {
            $client->loginUser($user);
            $client->request('POST', '/admin/push-test');
            self::assertResponseStatusCodeSame(403);
            $client->request('GET', '/preferences');
            self::assertSelectorNotExists('[data-push-test]');
            $em = static::getContainer()->get(EntityManagerInterface::class);
            $user = $em->find(User::class, $user->getId());
            self::assertInstanceOf(User::class, $user);
            $user->addRole('ROLE_ADMIN');
            $em->flush();
            $client->loginUser($user);
            $client->request('GET', '/preferences');
            self::assertSelectorExists('[data-push-test]');
        } finally {
            $em->getConnection()->executeStatement('DELETE FROM app_user WHERE id = ?', [$user->getId()->toBinary()]);
        }
    }

    public function testSubscriptionsAreScopedToTheAuthenticatedUser(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = new User('push-owner-'.bin2hex(random_bytes(8)).'@example.test');
        $other = new User('push-other-'.bin2hex(random_bytes(8)).'@example.test');
        $em->persist($owner);
        $em->persist($other);
        $em->flush();
        $endpoint = 'https://fcm.googleapis.com/fcm/send/'.bin2hex(random_bytes(16));
        $payload = json_encode(['endpoint' => $endpoint, 'keys' => ['p256dh' => str_repeat('A', 87), 'auth' => str_repeat('B', 22)]], JSON_THROW_ON_ERROR);

        try {
            $client->loginUser($owner);
            $page = $client->request('GET', '/preferences');
            $token = $page->filter('[data-push-notifications]')->attr('data-csrf');
            $client->request('POST', '/api/push-subscription', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token], content: $payload);
            self::assertResponseIsSuccessful();
            self::assertSame(1, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM push_subscription WHERE user_id = ?', [$owner->getId()->toBinary()]));
            $client->request('POST', '/api/push-subscription/status', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token], content: json_encode(['endpoint' => $endpoint], JSON_THROW_ON_ERROR));
            self::assertResponseIsSuccessful();
            self::assertTrue(json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['subscribed']);

            $client->loginUser($other);
            $page = $client->request('GET', '/preferences');
            $token = $page->filter('[data-push-notifications]')->attr('data-csrf');
            $client->request('POST', '/api/push-subscription/status', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token], content: json_encode(['endpoint' => $endpoint], JSON_THROW_ON_ERROR));
            self::assertResponseIsSuccessful();
            self::assertFalse(json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['subscribed']);
            $client->request('DELETE', '/api/push-subscription', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token], content: json_encode(['endpoint' => $endpoint], JSON_THROW_ON_ERROR));
            self::assertResponseIsSuccessful();
            self::assertSame(1, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM push_subscription WHERE user_id = ?', [$owner->getId()->toBinary()]));

            $client->request('POST', '/api/push-subscription', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token], content: json_encode(['endpoint' => $endpoint, 'keys' => ['p256dh' => str_repeat('C', 87), 'auth' => str_repeat('D', 22)]], JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(403);
        } finally {
            $em->getConnection()->executeStatement('DELETE FROM app_user WHERE id IN (?, ?)', [$owner->getId()->toBinary(), $other->getId()->toBinary()]);
        }
    }

    public function testAnonymousClientsCannotRegisterAnEndpoint(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/push-subscription', server: ['CONTENT_TYPE' => 'application/json'], content: '{}');
        self::assertResponseStatusCodeSame(403);
    }

    public function testInvalidCsrfAndInternalEndpointsAreRejected(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = new User('push-security-'.bin2hex(random_bytes(8)).'@example.test');
        $em->persist($user);
        $em->flush();
        try {
            $client->loginUser($user);
            $client->request('POST', '/api/push-subscription', server: ['CONTENT_TYPE' => 'application/json'], content: '{}');
            self::assertResponseStatusCodeSame(403);

            $page = $client->request('GET', '/preferences');
            $token = $page->filter('[data-push-notifications]')->attr('data-csrf');
            $client->request('POST', '/api/push-subscription', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token], content: '{"endpoint":"https://127.0.0.1/private"}');
            self::assertResponseStatusCodeSame(400);
        } finally {
            $em->getConnection()->executeStatement('DELETE FROM app_user WHERE id = ?', [$user->getId()->toBinary()]);
        }
    }

    public function testPreferencesOfferExplicitNotificationActivationForThisDevice(): void
    {
        $client = static::createClient();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $user = new User('push-'.bin2hex(random_bytes(8)).'@example.test');
        $entityManager->persist($user);
        $entityManager->flush();

        try {
            $client->loginUser($user);
            $client->request('GET', '/preferences');

            self::assertResponseIsSuccessful();
            self::assertSelectorExists('[data-push-notifications] button[data-push-enable]');
            self::assertSelectorExists('[data-push-notifications] button[data-push-disable]');
            self::assertSelectorExists('[data-push-notifications] [role="status"]');
        } finally {
            $entityManager->getConnection()->executeStatement('DELETE FROM app_user WHERE id = ?', [$user->getId()->toBinary()]);
        }
    }
}
