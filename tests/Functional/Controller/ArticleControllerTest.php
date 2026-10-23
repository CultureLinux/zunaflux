<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Domain\Feed\ParsedFeedItem;
use App\Entity\Article;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Entity\UserPreference;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ArticleControllerTest extends WebTestCase
{
    public function testUnreadCounterWorksWithSubscribedFeeds(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = new User('zen-count-'.bin2hex(random_bytes(8)).'@example.test');
        $feed = new Feed('https://example.test/'.bin2hex(random_bytes(8)));
        $article = new Article($feed, new ParsedFeedItem('one', 'Article', null, null, null, null));
        foreach ([$user, $feed, $article, new Subscription($user, $feed)] as $entity) $em->persist($entity);
        $em->flush();
        try {
            $client->loginUser($user);
            $client->request('GET', '/api/statistics/counters');
            self::assertResponseIsSuccessful();
            $result = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(1, (int) $result['all']['unread_count']);
            self::assertSame((string) $feed->getId(), $result['feeds'][0]['id']);
        } finally {
            $em->getConnection()->executeStatement('DELETE FROM app_user WHERE id = ?', [$user->getId()->toBinary()]);
            $em->getConnection()->executeStatement('DELETE FROM feed WHERE id = ?', [$feed->getId()->toBinary()]);
        }
    }
    public function testListExposesOnlyLocalImagePathsForArticlesWithImages(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = new User('list-image-'.bin2hex(random_bytes(8)).'@example.test');
        $feed = new Feed('https://example.test/'.bin2hex(random_bytes(8)));
        $withImage = new Article($feed, new ParsedFeedItem('image', 'Avec image', null, null, '<img src="https://example.test/image.jpg">', null));
        $withoutImage = new Article($feed, new ParsedFeedItem('plain', 'Sans image', null, null, '<p>Texte</p>', null));
        foreach ([$user, $feed, $withImage, $withoutImage, new Subscription($user, $feed)] as $entity) $em->persist($entity);
        $em->flush();
        try {
            $client->loginUser($user);
            $client->request('GET', '/api/articles');
            self::assertResponseIsSuccessful();
            $items = array_column(json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['items'], null, 'id');
            self::assertSame('/api/articles/'.$withImage->getId().'/images/0', $items[(string) $withImage->getId()]['imageUrl']);
            self::assertNull($items[(string) $withoutImage->getId()]['imageUrl']);
        } finally {
            $em->getConnection()->executeStatement('DELETE FROM app_user WHERE id = ?', [$user->getId()->toBinary()]);
            $em->getConnection()->executeStatement('DELETE FROM feed WHERE id = ?', [$feed->getId()->toBinary()]);
        }
    }
    public function testHomeRedirectsAuthenticatedUsersToReading(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');
        self::assertResponseIsSuccessful();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = new User('home-'.bin2hex(random_bytes(8)).'@example.test');
        $em->persist($user);
        $em->flush();
        try {
            $client->loginUser($user);
            $client->request('GET', '/');
            self::assertResponseRedirects('/articles');
            $crawler = $client->request('GET', '/articles');
            self::assertSame('/articles', $crawler->filter('nav[aria-label="Navigation principale"] a')->first()->attr('href'));
        } finally {
            $em->getConnection()->executeStatement('DELETE FROM app_user WHERE id = ?', [$user->getId()->toBinary()]);
        }
    }

    public function testSavedThemeIsAppliedAcrossAuthenticatedPages(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = new User('theme-'.bin2hex(random_bytes(8)).'@example.test');
        $preference = new UserPreference($user);
        $em->persist($user);
        $em->persist($preference);
        $em->flush();
        try {
            $client->loginUser($user);
            $crawler = $client->request('GET', '/preferences');
            self::assertSelectorExists('html[data-theme="system"]');
            $form = $crawler->filter('main form')->first()->form();
            $form['theme'] = 'light';
            $client->submit($form);
            $client->followRedirect();
            self::assertSelectorExists('html[data-theme="light"]');
            $client->request('GET', '/subscriptions');
            self::assertSelectorExists('html[data-theme="light"]');
            $crawler = $client->request('GET', '/preferences');
            $form = $crawler->filter('main form')->first()->form();
            $form['theme'] = 'dark';
            $client->submit($form);
            $client->followRedirect();
            self::assertSelectorExists('html[data-theme="dark"]');
        } finally {
            $em->getConnection()->executeStatement('DELETE FROM app_user WHERE id = ?', [$user->getId()->toBinary()]);
        }
    }

    public function testMarkFeedReadOnlyChangesTheOwnersArticlesAndRequiresCsrf(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = new User('mark-feed-'.bin2hex(random_bytes(8)).'@example.test');
        $other = new User('mark-other-'.bin2hex(random_bytes(8)).'@example.test');
        $feed = new Feed('https://example.test/'.bin2hex(random_bytes(8)).'/mark-feed');
        $feed->recordFailure(new \RuntimeException('Collecte indisponible'));
        $article = new Article($feed, new ParsedFeedItem('one', 'Article', null, null, null, null));
        $subscription = new Subscription($owner, $feed);
        $otherSubscription = new Subscription($other, $feed);
        $preference = new UserPreference($owner);
        $preference->update(['sidebarCollapsed' => true]);
        foreach ([$owner, $other, $feed, $article, $subscription, $otherSubscription, $preference] as $entity) $em->persist($entity);
        $em->flush();
        try {
            $client->loginUser($owner);
            $client->request('POST', '/subscriptions/'.$subscription->getId().'/mark-read', ['_token' => 'invalid']);
            self::assertResponseStatusCodeSame(403);
            $crawler = $client->request('GET', '/articles');
            self::assertSelectorExists('[data-reading-source][data-unread="1"][data-read="0"]');
            self::assertSelectorExists('[data-reading-source] [data-feed-warning]');
            self::assertSame('1', trim($crawler->filter('[data-unread-subscription="'.$subscription->getId().'"]')->text()));
            $form = $crawler->filter('form[action="/subscriptions/'.$subscription->getId().'/mark-read"]')->form();
            $client->submit($form);
            self::assertResponseRedirects();
            self::assertStringContainsString('/articles?', (string) $client->getResponse()->headers->get('Location'));
            $connection = $em->getConnection();
            self::assertSame(1, (int) $connection->fetchOne('SELECT is_read FROM article_state WHERE user_id = ? AND article_id = ?', [$owner->getId()->toBinary(), $article->getId()->toBinary()], [\Doctrine\DBAL\ParameterType::BINARY, \Doctrine\DBAL\ParameterType::BINARY]));
            self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM article_state WHERE user_id = ? AND article_id = ?', [$other->getId()->toBinary(), $article->getId()->toBinary()]));
            $crawler = $client->followRedirect();
            self::assertSame('0', trim($crawler->filter('[data-unread-subscription="'.$subscription->getId().'"]')->text()));
            self::assertSelectorExists('[data-reading-source][data-unread="0"][data-read="1"]');
            $client->request('POST', '/subscriptions/'.$otherSubscription->getId().'/mark-read', ['_token' => 'invalid']);
            self::assertResponseStatusCodeSame(404);
        } finally {
            $connection = $em->getConnection();
            $connection->executeStatement('DELETE FROM app_user WHERE id IN (?, ?)', [$owner->getId()->toBinary(), $other->getId()->toBinary()]);
            $connection->executeStatement('DELETE FROM feed WHERE id = ?', [$feed->getId()->toBinary()]);
        }
    }

    public function testDesktopLayoutUsesPreferenceAndShowsOnlyOwnActiveSources(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = new User('layout-'.bin2hex(random_bytes(8)).'@example.test');
        $other = new User('layout-other-'.bin2hex(random_bytes(8)).'@example.test');
        $feed = new Feed('https://example.test/'.bin2hex(random_bytes(8)).'/layout');
        $otherFeed = new Feed('https://example.test/'.bin2hex(random_bytes(8)).'/private');
        $feed->refreshMetadata('Ma source de lecture', null);
        $otherFeed->refreshMetadata('Source privée extérieure', null);
        $preference = new UserPreference($user);
        foreach ([$user, $other, $feed, $otherFeed, $preference, new Subscription($user, $feed), new Subscription($other, $otherFeed)] as $entity) $em->persist($entity);
        $em->flush();
        try {
            $client->loginUser($user);
            $client->request('GET', '/articles');
            self::assertResponseIsSuccessful();
            self::assertSelectorNotExists('.reading-workspace');
            $preference->update(['sidebarCollapsed' => true]);
            $em->flush();
            $client->request('GET', '/articles');
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('.reading-workspace .reading-sidebar');
            self::assertStringContainsString('Ma source de lecture', $client->getResponse()->getContent());
            self::assertStringNotContainsString('Source privée extérieure', $client->getResponse()->getContent());
        } finally {
            $connection = $em->getConnection();
            $connection->executeStatement('DELETE FROM app_user WHERE id IN (?, ?)', [$user->getId()->toBinary(), $other->getId()->toBinary()]);
            $connection->executeStatement('DELETE FROM feed WHERE id IN (?, ?)', [$feed->getId()->toBinary(), $otherFeed->getId()->toBinary()]);
        }
    }

    /** Removing the reading access control must make this contract fail. */
    public function testTheReadingPageRequiresAuthentication(): void
    {
        $client = static::createClient();
        $client->request('GET', '/articles');

        self::assertResponseRedirects('/login');
    }

    public function testArticleImagesRequireAuthentication(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/articles/018f0000-0000-7000-8000-000000000000/images/0');

        self::assertResponseRedirects('/login');
    }

    /** Removing JSON endpoint protection must make this contract fail. */
    public function testTheReadingJsonEndpointRequiresAuthentication(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/articles');

        self::assertResponseRedirects('/login');
    }

    /** Removing the subscription join from the query must make this contract fail. */
    public function testTheReadingJsonEndpointListsOnlyTheAuthenticatedUsersSubscribedArticles(): void
    {
        $client = static::createClient();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $user = new User('reader-'.bin2hex(random_bytes(8)).'@example.test');
        $feed = new Feed('https://example.test/'.bin2hex(random_bytes(8)).'/feed.xml');
        $article = new Article($feed, new ParsedFeedItem('article-1', 'Article privé&#58; veille', null, '<p>J&#8217;ai découvert &amp; testé [&#8230;]</p>', null, null));
        $subscription = new Subscription($user, $feed);
        $category = new \App\Entity\Category($user, 'Actualités');
        $subscription->assignCategory($category);
        $entityManager->persist($user);
        $entityManager->persist($category);
        $entityManager->persist($feed);
        $entityManager->persist($article);
        $entityManager->persist($subscription);
        $entityManager->flush();

        try {
            $client->loginUser($user);
            $client->request('GET', '/api/articles');

            self::assertResponseIsSuccessful();
            self::assertJson($client->getResponse()->getContent());
            $payload = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
            self::assertContains('Article privé: veille', array_column($payload['items'], 'title'));
            self::assertSame((string) $subscription->getId(), $payload['items'][0]['subscriptionId']);
            self::assertSame((string) $category->getId(), $payload['items'][0]['categoryId']);
            self::assertSame('Actualités', $payload['items'][0]['categoryName']);
            self::assertSame('J’ai découvert & testé […]', $payload['items'][0]['summary']);
            $client->request('GET', '/api/articles?subscription='.$subscription->getId());
            self::assertResponseIsSuccessful();
            $filtered = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(['Article privé: veille'], array_column($filtered['items'], 'title'));
            $client->request('GET', '/api/articles?category='.$category->getId());
            self::assertResponseIsSuccessful();
            $filtered = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(['Article privé: veille'], array_column($filtered['items'], 'title'));
            $client->request('GET', '/api/articles/'.$article->getId());
            self::assertResponseIsSuccessful();
            $detail = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('Article privé: veille', $detail['title']);
        } finally {
            $connection = $entityManager->getConnection();
            $connection->executeStatement('DELETE FROM article WHERE id = ?', [$article->getId()->toBinary()]);
            $connection->executeStatement('DELETE FROM subscription WHERE id = ?', [$subscription->getId()->toBinary()]);
            $connection->executeStatement('DELETE FROM feed WHERE id = ?', [$feed->getId()->toBinary()]);
            $connection->executeStatement('DELETE FROM app_user WHERE id = ?', [$user->getId()->toBinary()]);
        }
    }

    /** Removing either the FULLTEXT condition or the subscription join must make this contract fail. */
    public function testSearchReturnsOnlyMatchingArticlesVisibleToTheAuthenticatedUser(): void
    {
        $client = static::createClient();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $user = new User('search-reader-'.bin2hex(random_bytes(8)).'@example.test');
        $otherUser = new User('search-other-'.bin2hex(random_bytes(8)).'@example.test');
        $feed = new Feed('https://search.test/'.bin2hex(random_bytes(8)).'/feed.xml');
        $hiddenFeed = new Feed('https://search-hidden.test/'.bin2hex(random_bytes(8)).'/feed.xml');
        $feed->refreshMetadata('Journal Veille Technique', null);
        $hiddenFeed->refreshMetadata('Journal Veille Technique', null);
        $visibleArticle = new Article($feed, new ParsedFeedItem('visible', 'Observabilité Kubernetes', null, 'Métriques et traces.', null, null));
        $hiddenArticle = new Article($hiddenFeed, new ParsedFeedItem('hidden', 'Observabilité confidentielle', null, 'Ne doit pas être exposé.', null, null));
        $subscription = new Subscription($user, $feed);
        $subscription->rename('Ma source personnelle');
        $entityManager->persist($user);
        $entityManager->persist($otherUser);
        $entityManager->persist($feed);
        $entityManager->persist($hiddenFeed);
        $entityManager->persist($visibleArticle);
        $entityManager->persist($hiddenArticle);
        $entityManager->persist($subscription);
        $entityManager->flush();

        try {
            $client->loginUser($user);
            $client->request('GET', '/api/articles?q=observabilite');

            self::assertResponseIsSuccessful();
            $payload = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(['Observabilité Kubernetes'], array_column($payload['items'], 'title'));
            foreach (['veille technique', 'source personnelle'] as $term) {
                $client->request('GET', '/api/articles?q='.urlencode($term));
                self::assertResponseIsSuccessful();
                $payload = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
                self::assertSame(['Observabilité Kubernetes'], array_column($payload['items'], 'title'));
            }
        } finally {
            $connection = $entityManager->getConnection();
            $connection->executeStatement('DELETE FROM article WHERE id IN (?, ?)', [$visibleArticle->getId()->toBinary(), $hiddenArticle->getId()->toBinary()]);
            $connection->executeStatement('DELETE FROM subscription WHERE id = ?', [$subscription->getId()->toBinary()]);
            $connection->executeStatement('DELETE FROM feed WHERE id = ?', [$feed->getId()->toBinary()]);
            $connection->executeStatement('DELETE FROM feed WHERE id = ?', [$hiddenFeed->getId()->toBinary()]);
            $connection->executeStatement('DELETE FROM app_user WHERE id IN (?, ?)', [$user->getId()->toBinary(), $otherUser->getId()->toBinary()]);
        }
    }

    /** Removing the authenticated navigation must make this contract fail. */
    public function testTheReadingPageProvidesTheAuthenticatedNavigation(): void
    {
        $client = static::createClient();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $user = new User('navigation-'.bin2hex(random_bytes(8)).'@example.test');
        $entityManager->persist($user);
        $entityManager->flush();

        try {
            $client->loginUser($user);
            $client->request('GET', '/articles');

            self::assertResponseIsSuccessful();
            self::assertSelectorExists('nav[aria-label="Navigation principale"] a[href="/articles"]');
            self::assertSelectorExists('form[action="/logout"][method="post"] input[name="_csrf_token"]');
            self::assertSelectorCount(2, '[data-article-dialog] [data-article-read]');
            self::assertSelectorCount(2, '[data-article-dialog] [data-article-favorite]');
            self::assertSelectorCount(2, '[data-article-dialog] [data-article-saved]');
            self::assertSelectorCount(2, '[data-article-dialog] [data-article-link]');
            self::assertSelectorNotExists('[data-mark-before]');
            self::assertSelectorExists('[data-read-filter] option[value="0"]');
            self::assertSelectorExists('[data-select-all]');
            self::assertSelectorExists('[data-clear-source]');
            self::assertSelectorExists('[data-bulk-read][aria-label]');
            self::assertSelectorExists('[data-bulk-unsave][aria-label]');
        } finally {
            $entityManager->getConnection()->executeStatement('DELETE FROM app_user WHERE id = ?', [$user->getId()->toBinary()]);
        }
    }
}
