<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

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

    public function testAnAuthenticatedUserCanImportFreshRssOpml(): void
    {
        $client = static::createClient();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $user = new User('opml-test-'.bin2hex(random_bytes(8)).'@example.test');
        $entityManager->persist($user);
        $entityManager->flush();
        $feedUrl = 'https://opml-example.test/'.bin2hex(random_bytes(8)).'/feed.xml';
        $path = tempnam(sys_get_temp_dir(), 'opml-');
        self::assertNotFalse($path);
        file_put_contents($path, '<opml version="2.0"><body><outline text="Veille"><outline text="Example" type="rss" xmlUrl="'.$feedUrl.'" /></outline></body></opml>');

        try {
            $client->loginUser($user);
            $crawler = $client->request('GET', '/subscriptions');
            $client->submit($crawler->selectButton('Importer OPML')->form(), [], [
                'opml_file' => new UploadedFile($path, 'subscriptions.opml', 'text/xml', null, true),
            ]);

            self::assertResponseRedirects('/subscriptions');
            self::assertSame(1, (int) $entityManager->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM subscription WHERE user_id = ?',
                [$user->getId()->toBinary()],
            ));
            self::assertSame(1, (int) $entityManager->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM category WHERE user_id = ? AND name = ?',
                [$user->getId()->toBinary(), 'Veille'],
            ));
        } finally {
            @unlink($path);
            $entityManager->getConnection()->executeStatement('DELETE FROM app_user WHERE id = ?', [$user->getId()->toBinary()]);
            $entityManager->getConnection()->executeStatement('DELETE FROM feed WHERE canonical_url = ?', [$feedUrl]);
        }
    }
}
