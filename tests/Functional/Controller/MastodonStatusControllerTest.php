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

final class MastodonStatusControllerTest extends WebTestCase
{
    public function testConnectedUserReceivesARecoverableErrorWhenNoMastodonAccountIsConnected(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = new User('mastodon-status-'.bin2hex(random_bytes(8)).'@example.test');
        $feed = new Feed('https://example.test/'.bin2hex(random_bytes(8)));
        $article = new Article($feed, new ParsedFeedItem('one', 'Article à publier', 'https://example.test/article', null, null, null));
        foreach ([$user, $feed, $article, new Subscription($user, $feed)] as $entity) $em->persist($entity);
        $em->flush();

        try {
            $client->loginUser($user);
            $client->request('POST', '/api/articles/'.$article->getId().'/mastodon-status', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => static::getContainer()->get('security.csrf.token_manager')->getToken('mastodon-status-'.$article->getId())->getValue()], content: json_encode(['status' => 'Article à publier https://example.test/article', 'visibility' => 'public'], JSON_THROW_ON_ERROR));

            self::assertResponseStatusCodeSame(409);
            self::assertJsonStringEqualsJsonString('{"error":"Connectez d’abord votre compte Mastodon."}', (string) $client->getResponse()->getContent());
        } finally {
            $em->getConnection()->executeStatement('DELETE FROM app_user WHERE id = ?', [$user->getId()->toBinary()]);
            $em->getConnection()->executeStatement('DELETE FROM feed WHERE id = ?', [$feed->getId()->toBinary()]);
        }
    }
}
