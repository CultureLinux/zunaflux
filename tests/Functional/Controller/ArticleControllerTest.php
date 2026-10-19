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
        $article = new Article($feed, new ParsedFeedItem('article-1', 'Article privé', null, 'Résumé privé', null, null));
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
            self::assertContains('Article privé', array_column($payload['items'], 'title'));
        } finally {
            $connection = $entityManager->getConnection();
            $connection->executeStatement('DELETE FROM article WHERE id = ?', [$article->getId()->toBinary()]);
            $connection->executeStatement('DELETE FROM subscription WHERE id = ?', [$subscription->getId()->toBinary()]);
            $connection->executeStatement('DELETE FROM feed WHERE id = ?', [$feed->getId()->toBinary()]);
            $connection->executeStatement('DELETE FROM app_user WHERE id = ?', [$user->getId()->toBinary()]);
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
            self::assertSelectorExists('form[action="/logout"] input[name="_token"]');
        } finally {
            $entityManager->getConnection()->executeStatement('DELETE FROM app_user WHERE id = ?', [$user->getId()->toBinary()]);
        }
    }
}
