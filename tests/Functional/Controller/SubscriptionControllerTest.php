<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\User;
use App\Entity\Feed;
use App\Entity\Subscription;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SubscriptionControllerTest extends WebTestCase
{
    public function testMovingSubscriptionChecksOwnershipAndCsrf(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = new User('move-owner-'.bin2hex(random_bytes(8)).'@example.test');
        $other = new User('move-other-'.bin2hex(random_bytes(8)).'@example.test');
        $feed = new Feed('https://example.test/'.bin2hex(random_bytes(8)));
        $article = new \App\Entity\Article($feed, new \App\Domain\Feed\ParsedFeedItem('move-test', 'Article', null, null, null, null));
        $subscription = new Subscription($owner, $feed);
        $category = new \App\Entity\Category($owner, 'Destination');
        $foreign = new \App\Entity\Category($other, 'Privée');
        foreach ([$owner, $other, $feed, $article, $subscription, $category, $foreign] as $entity) $em->persist($entity);
        $em->flush();
        try {
            $client->loginUser($owner);
            $page = $client->request('GET', '/subscriptions');
            self::assertSelectorExists('[data-subscription-item] [aria-label="1 articles en base"]');
            $url = '/subscriptions/'.$subscription->getId().'/category';
            $token = $page->filter('form[action="'.$url.'"] input[name="_token"]')->attr('value');
            $client->request('POST', $url, ['category_id' => (string) $category->getId()]);
            self::assertResponseStatusCodeSame(403);
            $client->request('POST', $url, ['_token' => $token, 'category_id' => (string) $foreign->getId()]);
            self::assertResponseStatusCodeSame(403);
            $client->request('POST', $url, ['_token' => $token, 'category_id' => (string) $category->getId()]);
            self::assertResponseRedirects('/subscriptions');
            $em->clear();
            self::assertTrue($em->getRepository(Subscription::class)->find($subscription->getId())->getCategory()->getId()->equals($category->getId()));
        } finally {
            $em->getConnection()->executeStatement('DELETE FROM app_user WHERE id IN (?, ?)', [$owner->getId()->toBinary(), $other->getId()->toBinary()]);
            $em->getConnection()->executeStatement('DELETE FROM feed WHERE id = ?', [$feed->getId()->toBinary()]);
        }
    }

    public function testDiscoveredFeedCanUseANewOrExistingOwnedCategory(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $suffix = bin2hex(random_bytes(8));
        $user = new User('discover-category-'.$suffix.'@example.test');
        $em->persist($user);
        $em->flush();
        try {
            $client->loginUser($user);
            $crawler = $client->request('GET', '/subscriptions');
            $token = $crawler->filter('[data-discovery-results] input[name="_token"]')->attr('value');
            $client->request('POST', '/subscriptions', ['intent' => 'subscribe-feed', '_token' => $token, 'feed_url' => 'https://example.test/'.$suffix.'/one', 'discovery_category' => 'new', 'new_category' => 'Veille']);
            self::assertResponseRedirects('/subscriptions');
            $category = $em->getRepository(\App\Entity\Category::class)->findOneBy(['user' => $user, 'name' => 'Veille']);
            self::assertNotNull($category);
            $first = $em->getRepository(Feed::class)->findOneBy(['canonicalUrl' => 'https://example.test/'.$suffix.'/one']);
            self::assertTrue($em->getRepository(Subscription::class)->findOneBy(['user' => $user, 'feed' => $first])->getCategory()->getId()->equals($category->getId()));
            $client->request('POST', '/subscriptions', ['intent' => 'subscribe-feed', '_token' => $token, 'feed_url' => 'https://example.test/'.$suffix.'/two', 'discovery_category' => (string) $category->getId()]);
            self::assertResponseRedirects('/subscriptions');
            $second = $em->getRepository(Feed::class)->findOneBy(['canonicalUrl' => 'https://example.test/'.$suffix.'/two']);
            self::assertTrue($em->getRepository(Subscription::class)->findOneBy(['user' => $user, 'feed' => $second])->getCategory()->getId()->equals($category->getId()));
        } finally {
            $em->getConnection()->executeStatement('DELETE FROM app_user WHERE id = ?', [$user->getId()->toBinary()]);
            $em->getConnection()->executeStatement('DELETE FROM feed WHERE canonical_url IN (?, ?)', ['https://example.test/'.$suffix.'/one', 'https://example.test/'.$suffix.'/two']);
        }
    }

    public function testDisablingSubscriptionShowsInactiveStatus(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = new User('inactive-'.bin2hex(random_bytes(8)).'@example.test');
        $feed = new Feed('https://example.test/'.bin2hex(random_bytes(8)).'/feed');
        $subscription = new Subscription($user, $feed);
        foreach ([$user, $feed, $subscription] as $entity) $em->persist($entity);
        $em->flush();
        try {
            $client->loginUser($user);
            $crawler = $client->request('GET', '/subscriptions');
            $form = $crawler->filter('form[action="/subscriptions/'.$subscription->getId().'/update"]')->form();
            $form['enabled']->untick();
            $client->submit($form);
            self::assertResponseRedirects('/subscriptions');
            $client->followRedirect();
            self::assertSelectorTextContains('.subscription-inactive', 'Inactif');
            $em->clear();
            self::assertFalse($em->getRepository(Subscription::class)->find($subscription->getId())->isEnabled());
        } finally {
            $em->getConnection()->executeStatement('DELETE FROM app_user WHERE id = ?', [$user->getId()->toBinary()]);
            $em->getConnection()->executeStatement('DELETE FROM feed WHERE id = ?', [$feed->getId()->toBinary()]);
        }
    }

    public function testLiveDiscoveryRejectsInvalidUrlsAndCsrf(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = new User('discovery-'.bin2hex(random_bytes(8)).'@example.test');
        $em->persist($user);
        $em->flush();
        try {
            $client->loginUser($user);
            $crawler = $client->request('GET', '/subscriptions');
            $token = $crawler->filter('[data-discovery-form] input[name="_token"]')->attr('value');
            $client->request('POST', '/subscriptions/discovery/live', ['_token' => 'invalid', 'site_url' => 'https://example.com']);
            self::assertResponseStatusCodeSame(403);
            $client->request('POST', '/subscriptions/discovery/live', ['_token' => $token, 'site_url' => 'file:///etc/passwd']);
            self::assertResponseStatusCodeSame(400);
            self::assertStringContainsString('adresse HTTP ou HTTPS valide', $client->getResponse()->getContent());
        } finally {
            $em->getConnection()->executeStatement('DELETE FROM app_user WHERE id = ?', [$user->getId()->toBinary()]);
        }
    }

    public function testCategoryDeletionRemovesOnlyOwnersSubscriptionsAndStates(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $suffix = bin2hex(random_bytes(8));
        $owner = new User('category-owner-'.$suffix.'@example.test');
        $other = new User('category-other-'.$suffix.'@example.test');
        $category = new \App\Entity\Category($owner, 'Veille');
        $feed = new Feed('https://example.test/'.$suffix.'/feed');
        $article = new \App\Entity\Article($feed, new \App\Domain\Feed\ParsedFeedItem('article', 'Article', null, null, null, null));
        $subscription = new Subscription($owner, $feed);
        $subscription->assignCategory($category);
        $otherSubscription = new Subscription($other, $feed);
        $ownerState = new \App\Entity\ArticleState($owner, $article);
        $otherState = new \App\Entity\ArticleState($other, $article);
        foreach ([$owner, $other, $category, $feed, $article, $subscription, $otherSubscription, $ownerState, $otherState] as $entity) $em->persist($entity);
        $em->flush();
        try {
            $client->loginUser($other);
            $client->request('POST', '/categories/'.$category->getId().'/delete');
            self::assertResponseStatusCodeSame(403);
            $client->loginUser($owner);
            $crawler = $client->request('GET', '/subscriptions');
            $rename = $crawler->filter('form[action="/categories/'.$category->getId().'/update"]')->form(['category_name' => 'Veille renommée']);
            $client->submit($rename);
            self::assertResponseRedirects('/subscriptions');
            $crawler = $client->followRedirect();
            self::assertStringContainsString('Veille renommée', $crawler->text());
            $form = $crawler->filter('form[action="/categories/'.$category->getId().'/delete"]')->form();
            $client->submit($form);
            self::assertResponseRedirects('/subscriptions');
            $connection = $em->getConnection();
            self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM subscription WHERE id = ?', [$subscription->getId()->toBinary()]));
            self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM article_state WHERE user_id = ?', [$owner->getId()->toBinary()]));
            self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM subscription WHERE id = ?', [$otherSubscription->getId()->toBinary()]));
            self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM article_state WHERE user_id = ?', [$other->getId()->toBinary()]));
            self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM article WHERE id = ?', [$article->getId()->toBinary()]));
        } finally {
            $connection = $em->getConnection();
            $connection->executeStatement('DELETE FROM app_user WHERE id IN (?, ?)', [$owner->getId()->toBinary(), $other->getId()->toBinary()]);
            $connection->executeStatement('DELETE FROM feed WHERE id = ?', [$feed->getId()->toBinary()]);
        }
    }

    public function testFeedPublicationAgeBandsAndUnknownDates(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $suffix = bin2hex(random_bytes(8));
        $user = new User('feed-bands-'.$suffix.'@example.test');
        $em->persist($user);
        $feeds = [];
        foreach (['day' => '-1 hour', 'week' => '-2 days', 'month' => '-2 weeks', 'quarter' => '-2 months', 'year' => '-2 years', 'unknown' => null] as $band => $date) {
            $feed = new Feed('https://example.test/'.$suffix.'/'.$band);
            $feeds[] = $feed;
            $em->persist($feed);
            $em->persist(new Subscription($user, $feed));
            if (null !== $date) $em->persist(new \App\Entity\Article($feed, new \App\Domain\Feed\ParsedFeedItem($band, $band, null, null, null, new \DateTimeImmutable($date))));
        }
        $em->flush();
        try {
            $client->loginUser($user);
            $crawler = $client->request('GET', '/subscriptions');
            self::assertResponseIsSuccessful();
            foreach (['day', 'week', 'month', 'quarter', 'year'] as $band) self::assertCount(1, $crawler->filter('[data-feed-age="'.$band.'"]'));
            self::assertStringContainsString('plus d’un an', $crawler->filter('[data-feed-age="year"]')->text());
            self::assertStringContainsString('Date de publication inconnue', $crawler->text());
        } finally {
            $connection = $em->getConnection();
            $connection->executeStatement('DELETE FROM app_user WHERE id = ?', [$user->getId()->toBinary()]);
            foreach ($feeds as $feed) $connection->executeStatement('DELETE FROM feed WHERE id = ?', [$feed->getId()->toBinary()]);
        }
    }

    public function testFeedShowsItsMostRecentPublicationAndAge(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $suffix = bin2hex(random_bytes(8));
        $user = new User('feed-date-'.$suffix.'@example.test');
        $feed = new Feed('https://example.test/'.$suffix.'/feed');
        $feed->refreshMetadata('Source datée', null);
        $recent = new \DateTimeImmutable('-2 days');
        $old = new \DateTimeImmutable('-2 years');
        foreach ([$user, $feed, new Subscription($user, $feed),
            new \App\Entity\Article($feed, new \App\Domain\Feed\ParsedFeedItem('old', 'Ancien', null, null, null, $old)),
            new \App\Entity\Article($feed, new \App\Domain\Feed\ParsedFeedItem('new', 'Récent', null, null, null, $recent)),
        ] as $entity) $em->persist($entity);
        $em->flush();
        try {
            $client->loginUser($user);
            $crawler = $client->request('GET', '/subscriptions');
            self::assertResponseIsSuccessful();
            $date = $crawler->filter('[data-feed-age="week"]');
            self::assertCount(1, $date, $crawler->filter('main')->text());
            self::assertStringContainsString($recent->format('d/m/Y'), $date->text());
            self::assertStringContainsString('moins d’une semaine', $date->text());
        } finally {
            $connection = $em->getConnection();
            $connection->executeStatement('DELETE FROM app_user WHERE id = ?', [$user->getId()->toBinary()]);
            $connection->executeStatement('DELETE FROM feed WHERE id = ?', [$feed->getId()->toBinary()]);
        }
    }

    public function testOpmlExportCanBeImportedWithCategoriesAndWithoutOtherAccountsFeeds(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $suffix = bin2hex(random_bytes(8));
        $source = new User('roundtrip-source-'.$suffix.'@example.test');
        $target = new User('roundtrip-target-'.$suffix.'@example.test');
        $other = new User('roundtrip-other-'.$suffix.'@example.test');
        $category = new \App\Entity\Category($source, 'Culture & sciences');
        $categorized = new Feed('https://example.test/'.$suffix.'/categorized');
        $uncategorized = new Feed('https://example.test/'.$suffix.'/uncategorized');
        $private = new Feed('https://example.test/'.$suffix.'/private');
        $subscription = new Subscription($source, $categorized);
        $subscription->assignCategory($category);
        foreach ([$source, $target, $other, $category, $categorized, $uncategorized, $private, $subscription, new Subscription($source, $uncategorized), new Subscription($other, $private)] as $entity) $em->persist($entity);
        $em->flush();
        $path = tempnam(sys_get_temp_dir(), 'opml-roundtrip-');
        self::assertNotFalse($path);
        try {
            $client->loginUser($source);
            $crawler = $client->request('GET', '/preferences');
            $client->submit($crawler->filter('form[action="/exports/opml"]')->form());
            self::assertResponseIsSuccessful();
            file_put_contents($path, $client->getResponse()->getContent());
            $client->loginUser($target);
            $crawler = $client->request('GET', '/subscriptions');
            $form = $crawler->selectButton('Importer OPML')->form();
            $form['opml_file']->upload($path);
            $client->submit($form);
            self::assertResponseRedirects('/subscriptions');
            $em->clear();
            $target = $em->getRepository(User::class)->find($target->getId());
            $subscriptions = $em->getRepository(Subscription::class)->findBy(['user' => $target]);
            self::assertCount(2, $subscriptions);
            $categories = [];
            foreach ($subscriptions as $item) $categories[$item->getFeed()->getCanonicalUrl()] = $item->getCategory()?->getName();
            self::assertSame('Culture & sciences', $categories[$categorized->getCanonicalUrl()]);
            self::assertArrayHasKey($uncategorized->getCanonicalUrl(), $categories);
            self::assertNull($categories[$uncategorized->getCanonicalUrl()]);
            self::assertArrayNotHasKey($private->getCanonicalUrl(), $categories);
        } finally {
            @unlink($path);
            $connection = $em->getConnection();
            $connection->executeStatement('DELETE FROM app_user WHERE id IN (?, ?, ?)', [$source->getId()->toBinary(), $target->getId()->toBinary(), $other->getId()->toBinary()]);
            $connection->executeStatement('DELETE FROM feed WHERE id IN (?, ?, ?)', [$categorized->getId()->toBinary(), $uncategorized->getId()->toBinary(), $private->getId()->toBinary()]);
        }
    }

    public function testDiscoveryResultCannotBeReadByAnotherAccount(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $suffix = bin2hex(random_bytes(8));
        $owner = new User('discovery-owner-'.$suffix.'@example.test');
        $other = new User('discovery-other-'.$suffix.'@example.test');
        $em->persist($owner);
        $em->persist($other);
        $em->flush();
        try {
            $client->loginUser($owner);
            $crawler = $client->request('GET', '/subscriptions');
            $form = $crawler->selectButton('Chercher les flux')->form(['site_url' => 'https://example.com/']);
            $client->submit($form);
            self::assertResponseRedirects();
            $location = $client->getResponse()->headers->get('Location');
            $client->loginUser($other);
            $client->request('GET', $location);
            self::assertResponseStatusCodeSame(404);
        } finally {
            $em->getConnection()->executeStatement('DELETE FROM app_user WHERE id IN (?, ?)', [$owner->getId()->toBinary(), $other->getId()->toBinary()]);
        }
    }

    public function testOpmlExportPreservesCategoriesAndExcludesOtherUsersSubscriptions(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $suffix = bin2hex(random_bytes(8));
        $owner = new User('export-owner-'.$suffix.'@example.test');
        $other = new User('export-other-'.$suffix.'@example.test');
        $category = new \App\Entity\Category($owner, 'Veille & actualités');
        $feed = new Feed('https://example.test/'.$suffix.'/feed');
        $uncategorizedFeed = new Feed('https://example.test/'.$suffix.'/uncategorized');
        $privateFeed = new Feed('https://example.test/'.$suffix.'/private');
        $subscription = new Subscription($owner, $feed);
        $subscription->assignCategory($category);
        foreach ([$owner, $other, $category, $feed, $uncategorizedFeed, $privateFeed, $subscription, new Subscription($owner, $uncategorizedFeed), new Subscription($other, $privateFeed)] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        try {
            $client->loginUser($owner);
            $crawler = $client->request('GET', '/preferences');
            $client->submit($crawler->filter('form[action="/exports/opml"]')->form());
            self::assertResponseIsSuccessful();
            $items = (new \App\Application\Subscription\OpmlSubscriptionParser())->parse($client->getResponse()->getContent());
            $categoriesByUrl = [];
            foreach ($items as $item) {
                $categoriesByUrl[$item->url] = $item->categoryName;
            }
            self::assertCount(2, $categoriesByUrl);
            self::assertSame('Veille & actualités', $categoriesByUrl[$feed->getCanonicalUrl()]);
            self::assertArrayHasKey($uncategorizedFeed->getCanonicalUrl(), $categoriesByUrl);
            self::assertNull($categoriesByUrl[$uncategorizedFeed->getCanonicalUrl()]);
            self::assertArrayNotHasKey($privateFeed->getCanonicalUrl(), $categoriesByUrl);
        } finally {
            $connection = $em->getConnection();
            $connection->executeStatement('DELETE FROM app_user WHERE id IN (?, ?)', [$owner->getId()->toBinary(), $other->getId()->toBinary()]);
            $connection->executeStatement('DELETE FROM feed WHERE id IN (?, ?, ?)', [$feed->getId()->toBinary(), $uncategorizedFeed->getId()->toBinary(), $privateFeed->getId()->toBinary()]);
        }
    }

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
        $incident = new \App\Entity\FeedIncident($feed, new \RuntimeException('Feed request returned HTTP 404.'));
        foreach ([$admin, $other, $feed, $article, $state, $subscription, $incident] as $entity) $em->persist($entity);
        $em->flush();
        try {
            $client->loginUser($other);
            $client->request('POST', '/subscriptions/'.$subscription->getId().'/delete');
            self::assertResponseStatusCodeSame(403);
            $client->request('POST', '/admin/feeds/'.$feed->getId().'/delete');
            self::assertResponseStatusCodeSame(403);
            $client->request('POST', '/admin/incidents/'.$incident->getId().'/retry');
            self::assertResponseStatusCodeSame(403);
            $client->loginUser($admin);
            $client->request('POST', '/admin/incidents/'.$incident->getId().'/retry');
            self::assertResponseStatusCodeSame(403);
            $crawler = $client->request('GET', '/admin');
            self::assertCount(1, $crawler->filter('h2')->reduce(static fn ($node): bool => 'Comptes' === trim($node->text())));
            self::assertStringContainsString('Le flux est introuvable sur le site (HTTP 404).', $crawler->filter('[data-open-incidents]')->text());
            self::assertStringContainsString('Feed request returned HTTP 404.', $crawler->filter('[data-open-incidents]')->text());
            self::assertSelectorExists('[data-open-incidents] form[action="/admin/feeds/'.$feed->getId().'/delete"]');
            self::assertSelectorExists('[data-open-incidents] form[action="/admin/incidents/'.$incident->getId().'/retry"]');
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
            self::assertSelectorExists('button[data-open-delete="edit-subscription-'.$subscription->getId().'"]');
            self::assertSelectorExists('dialog#edit-subscription-'.$subscription->getId().' form[action="/subscriptions/'.$subscription->getId().'/update"]');
            self::assertSelectorExists('dialog#edit-subscription-'.$subscription->getId().' button[data-open-delete="delete-'.$subscription->getId().'"]');
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
            $client->request('GET', '/subscriptions');
            $session = $client->getSession();
            self::assertNotNull($session);
            $request = new \Symfony\Component\HttpFoundation\Request();
            $request->setSession($session);
            $requestStack = static::getContainer()->get('request_stack');
            $requestStack->push($request);
            try {
                $token = static::getContainer()->get('security.csrf.token_manager')->getToken('create-category')->getValue();
            } finally {
                $requestStack->pop();
                $session->save();
            }
            $client->request('POST', '/subscriptions', ['intent' => 'create-category', '_token' => $token, 'category_name' => 'Veille']);

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
            $form = $crawler->selectButton('Importer OPML')->form();
            $form['opml_file']->upload($path);
            $client->submit($form);

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

    public function testOpmlImportReportsDuplicatesAndInvalidEntries(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $suffix = bin2hex(random_bytes(8));
        $user = new User('opml-report-'.$suffix.'@example.test');
        $url = 'https://example.test/'.$suffix.'/feed';
        $em->persist($user);
        $em->flush();
        $path = tempnam(sys_get_temp_dir(), 'opml-');
        self::assertNotFalse($path);
        file_put_contents($path, '<opml><body><outline xmlUrl="'.$url.'"/><outline xmlUrl="'.$url.'"/><outline xmlUrl="not-a-url"/></body></opml>');

        try {
            $client->loginUser($user);
            $crawler = $client->request('GET', '/subscriptions');
            $form = $crawler->selectButton('Importer OPML')->form();
            $form['opml_file']->upload($path);
            $client->submit($form);
            $crawler = $client->followRedirect();
            self::assertStringContainsString('1 flux importé(s), 1 déjà présent(s), 1 non importé(s)', $crawler->text());
        } finally {
            @unlink($path);
            $connection = $em->getConnection();
            $connection->executeStatement('DELETE FROM app_user WHERE id = ?', [$user->getId()->toBinary()]);
            $connection->executeStatement('DELETE FROM feed WHERE canonical_url = ?', [$url]);
        }
    }
}
