<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminFeedSyncControllerTest extends WebTestCase
{
    public function testAdminCanStartASynchronizationWithTheCsrfHeader(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $admin = new User('feed-sync-admin-'.bin2hex(random_bytes(8)).'@example.test');
        $admin->addRole('ROLE_ADMIN');
        $em->persist($admin);
        $em->flush();

        try {
            $client->loginUser($admin);
            $crawler = $client->request('GET', '/admin');
            $token = $crawler->filter('form[data-feed-sync-form] input[name="_token"]')->attr('value');

            $client->request('POST', '/admin/feed-sync', ['scope' => 'all'], server: [
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_CSRF_TOKEN' => $token,
            ]);

            self::assertResponseStatusCodeSame(202);
        } finally {
            $connection = $em->getConnection();
            $connection->executeStatement('DELETE FROM feed_sync_run');
            $connection->executeStatement('DELETE FROM app_user WHERE id = ?', [$admin->getId()->toBinary()]);
        }
    }
}
