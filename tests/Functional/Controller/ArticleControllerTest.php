<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Domain\Feed\ParsedFeedItem;
use App\Entity\Article;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ArticleControllerTest extends WebTestCase
{
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
        $entityManager->persist($user);
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
            self::assertSame('J’ai découvert & testé […]', $payload['items'][0]['summary']);
            $client->request('GET', '/api/articles?subscription='.$subscription->getId());
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
