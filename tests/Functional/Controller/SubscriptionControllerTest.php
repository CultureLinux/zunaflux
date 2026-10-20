<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\User;
use App\Entity\Feed;
use App\Entity\Subscription;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class SubscriptionControllerTest extends WebTestCase
{
    public function testUnsubscribeRemovesOnlyOwnersStatesAndLeavesSharedData(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = new User('unsubscribe-owner-'.bin2hex(random_bytes(8)).'@example.test');
        $other = new User('unsubscribe-other-'.bin2hex(random_bytes(8)).'@example.test');
        $feed = new Feed('https://example.test/'.bin2hex(random_bytes(8)).'/shared');
        $article = new \App\Entity\Article($feed, new \App\Domain\Feed\ParsedFeedItem('shared', 'Article', null, null, null, null));
        $ownerState = new \App\Entity\ArticleState($owner, $article);
        $otherState = new \App\Entity\ArticleState($other, $article);
        $ownerState->setFavorite(true);
        $otherState->setFavorite(true);
        $subscription = new Subscription($owner, $feed);
        $otherSubscription = new Subscription($other, $feed);
        foreach ([$owner, $other, $feed, $article, $ownerState, $otherState, $subscription, $otherSubscription] as $entity) $em->persist($entity);
        $em->flush();
        try {
            $client->loginUser($other);
            $client->request('POST', '/subscriptions/'.$subscription->getId().'/delete', ['_token' => 'invalid']);
            self::assertResponseStatusCodeSame(403);
            $client->loginUser($owner);
            $crawler = $client->request('GET', '/subscriptions');
            $form = $crawler->filter('form[action="/subscriptions/'.$subscription->getId().'/delete"]')->form();
            $client->submit($form);
            self::assertResponseRedirects('/subscriptions');
            $connection = $em->getConnection();
            self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM subscription WHERE id = ?', [$subscription->getId()->toBinary()]));
            self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM article_state WHERE user_id = ? AND article_id = ?', [$owner->getId()->toBinary(), $article->getId()->toBinary()]));
            self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM subscription WHERE id = ?', [$otherSubscription->getId()->toBinary()]));
            self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM article_state WHERE user_id = ? AND article_id = ?', [$other->getId()->toBinary(), $article->getId()->toBinary()]));
            self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM article WHERE id = ?', [$article->getId()->toBinary()]));
        } finally {
            $connection = $em->getConnection();
            $connection->executeStatement('DELETE FROM feed WHERE id = ?', [$feed->getId()->toBinary()]);
            $connection->executeStatement('DELETE FROM app_user WHERE id IN (?, ?)', [$owner->getId()->toBinary(), $other->getId()->toBinary()]);
        }
    }

    public function testAdminCanSeeFeedsWithNoSubscriptions(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $admin = new User('unused-admin-'.bin2hex(random_bytes(8)).'@example.test');
        $admin->addRole('ROLE_ADMIN');
        $unused = new Feed('https://example.test/'.bin2hex(random_bytes(8)).'/unused');
        $used = new Feed('https://example.test/'.bin2hex(random_bytes(8)).'/used');
        $unused->refreshMetadata('Flux sans abonné', null);
        $used->refreshMetadata('Flux encore suivi', null);
        $subscription = new Subscription($admin, $used);
        $subscription->disable();
        foreach ([$admin, $unused, $used, $subscription] as $entity) $em->persist($entity);
        $em->flush();
        try {
            $client->loginUser($admin);
            $crawler = $client->request('GET', '/admin');
            self::assertResponseIsSuccessful();
            $section = $crawler->filter('[data-unused-feeds]');
            self::assertStringContainsString('Flux sans abonné', $section->text());
            self::assertStringNotContainsString('Flux encore suivi', $section->text());
            self::assertSelectorExists('[data-unused-feeds] form[action="/admin/feeds/'.$unused->getId().'/delete"]');
            self::assertSelectorNotExists('[data-unused-feeds] input[name="confirmation"]');
            $form = $section->filter('form[action="/admin/feeds/'.$unused->getId().'/delete"]')->form();
            $em->persist(new Subscription($admin, $unused));
            $em->flush();
            $client->submit($form);
            self::assertResponseStatusCodeSame(409);
        } finally {
            $connection = $em->getConnection();
            $connection->executeStatement('DELETE FROM app_user WHERE id = ?', [$admin->getId()->toBinary()]);
            $connection->executeStatement('DELETE FROM feed WHERE id IN (?, ?)', [$unused->getId()->toBinary(), $used->getId()->toBinary()]);
        }
    }

    public function testOnlyAdminCanDeleteASharedFeedAndItsArticlesForEveryone(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $admin = new User('delete-admin-'.bin2hex(random_bytes(8)).'@example.test');
        $admin->addRole('ROLE_ADMIN');
        $other = new User('delete-user-'.bin2hex(random_bytes(8)).'@example.test');
        $feed = new Feed('https://example.test/'.bin2hex(random_bytes(8)).'/shared');
        $article = new \App\Entity\Article($feed, new \App\Domain\Feed\ParsedFeedItem('shared', 'Article', null, null, null, null));
        $state = new \App\Entity\ArticleState($other, $article);
        $state->setFavorite(true);
        $subscription = new Subscription($other, $feed);
        $incident = new \App\Entity\FeedIncident($feed, new \RuntimeException('Flux indisponible'));
        foreach ([$admin, $other, $feed, $article, $state, $subscription, $incident] as $entity) $em->persist($entity);
        $em->flush();
        try {
            $client->loginUser($other);
            $client->request('POST', '/subscriptions/'.$subscription->getId().'/delete');
            self::assertResponseStatusCodeSame(403);
            $client->request('POST', '/admin/feeds/'.$feed->getId().'/delete');
            self::assertResponseStatusCodeSame(403);
            $client->loginUser($admin);
            $crawler = $client->request('GET', '/admin');
            self::assertSelectorExists('[data-open-incidents] form[action="/admin/feeds/'.$feed->getId().'/delete"]');
            $form = $crawler->filter('form[action="/admin/feeds/'.$feed->getId().'/delete"]')->form();
            $client->submit($form);
            self::assertResponseRedirects('/admin');
            $connection = $em->getConnection();
            self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM feed WHERE id = ?', [$feed->getId()->toBinary()]));
            self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM subscription WHERE id = ?', [$subscription->getId()->toBinary()]));
            self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM article WHERE id = ?', [$article->getId()->toBinary()]));
            self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM article_state WHERE user_id = ?', [$other->getId()->toBinary()]));
        } finally {
            $connection = $em->getConnection();
            $connection->executeStatement('DELETE FROM feed WHERE id = ?', [$feed->getId()->toBinary()]);
            $connection->executeStatement('DELETE FROM app_user WHERE id IN (?, ?)', [$admin->getId()->toBinary(), $other->getId()->toBinary()]);
        }
    }

    public function testAnOpenIncidentIsVisibleOnlyForTheUsersOwnSource(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = new User('incident-test-'.bin2hex(random_bytes(8)).'@example.test');
        $other = new User('incident-other-'.bin2hex(random_bytes(8)).'@example.test');
        $feed = new Feed('https://example.test/'.bin2hex(random_bytes(8)).'/incident');
        $privateFeed = new Feed('https://example.test/'.bin2hex(random_bytes(8)).'/secret');
        $feed->refreshMetadata('Source indisponible', null);
        $privateFeed->refreshMetadata('Source privée', null);
        $feed->recordFailure(new \RuntimeException('Erreur de test'));
        $privateFeed->recordFailure(new \RuntimeException('Erreur privée'));
        foreach ([$user, $other, $feed, $privateFeed, new Subscription($user, $feed), new Subscription($other, $privateFeed), new \App\Entity\FeedIncident($feed, new \RuntimeException('Erreur de test')), new \App\Entity\FeedIncident($privateFeed, new \RuntimeException('Erreur privée'))] as $entity) $em->persist($entity);
        $em->flush();
        try {
            $client->loginUser($user);
            $crawler = $client->request('GET', '/subscriptions');
            self::assertResponseIsSuccessful();
            self::assertStringContainsString('Erreur de test', $crawler->text());
            self::assertStringNotContainsString('Erreur privée', $crawler->text());
            self::assertSelectorExists('[data-feed-incident]');
            self::assertSelectorExists('form[action$="/delete"][data-delete-subscription]');
        } finally {
            $connection = $em->getConnection();
            $connection->executeStatement('DELETE FROM app_user WHERE id IN (?, ?)', [$user->getId()->toBinary(), $other->getId()->toBinary()]);
            $connection->executeStatement('DELETE FROM feed WHERE id IN (?, ?)', [$feed->getId()->toBinary(), $privateFeed->getId()->toBinary()]);
        }
    }

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

    public function testChangingFeedUrlDoesNotChangeAnotherUsersSubscription(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = new User('url-owner-'.bin2hex(random_bytes(8)).'@example.test');
        $other = new User('url-other-'.bin2hex(random_bytes(8)).'@example.test');
        $suffix = bin2hex(random_bytes(8));
        $original = new Feed('https://example.test/'.$suffix.'/original');
        $subscription = new Subscription($user, $original);
        $otherSubscription = new Subscription($other, $original);
        foreach ([$user, $other, $original, $subscription, $otherSubscription] as $entity) $em->persist($entity);
        $em->flush();
        $newUrl = 'https://example.test/'.$suffix.'/new';
        try {
            $client->loginUser($user);
            $crawler = $client->request('GET', '/subscriptions');
            $form = $crawler->filter('form[action="/subscriptions/'.$subscription->getId().'/update"]')->form();
            $client->submit($form, ['feed_url' => $newUrl, 'custom_title' => 'Nouvelle source', 'category_id' => '', 'enabled' => '1']);
            self::assertResponseRedirects('/subscriptions');
            $em->clear();
            $updated = $em->getRepository(Subscription::class)->find($subscription->getId());
            $unchanged = $em->getRepository(Subscription::class)->find($otherSubscription->getId());
            self::assertSame($newUrl, $updated->getFeed()->getCanonicalUrl());
            self::assertSame($original->getCanonicalUrl(), $unchanged->getFeed()->getCanonicalUrl());
        } finally {
            $em->getConnection()->executeStatement('DELETE FROM app_user WHERE id IN (?, ?)', [$user->getId()->toBinary(), $other->getId()->toBinary()]);
            $em->getConnection()->executeStatement('DELETE FROM feed WHERE canonical_url IN (?, ?)', [$original->getCanonicalUrl(), $newUrl]);
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
