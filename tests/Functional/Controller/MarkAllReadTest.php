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

final class MarkAllReadTest extends WebTestCase
{
    public function testMarkAllReadClearsTheBacklogOfTheCurrentUserOnly(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $db = $em->getConnection();
        $suffix = bin2hex(random_bytes(8));
        $reader = new User('mark-all-'.$suffix.'@example.test');
        $other = new User('mark-all-other-'.$suffix.'@example.test');
        $feed = new Feed('https://example.test/mark-all-'.$suffix);
        foreach ([$reader, $other, $feed, new Subscription($reader, $feed), new Subscription($other, $feed)] as $entity) {
            $em->persist($entity);
        }
        for ($i = 0; $i < 3; ++$i) {
            $em->persist(new Article($feed, new ParsedFeedItem('item-'.$i, 'Item '.$i, null, null, null, null)));
        }
        $em->flush();

        try {
            $client->loginUser($reader);
            $crawler = $client->request('GET', '/articles');
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('#mark-all-read-dialog form[action="/articles/mark-all-read"]');
            $token = $crawler->filter('#mark-all-read-dialog input[name="_token"]')->attr('value');

            $client->request('POST', '/articles/mark-all-read', ['_token' => 'invalid']);
            self::assertResponseStatusCodeSame(403);
            self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM article_state WHERE user_id = ?', [$reader->getId()->toBinary()]));

            $client->request('POST', '/articles/mark-all-read', ['_token' => $token]);
            self::assertResponseRedirects('/articles');
            self::assertSame(3, (int) $db->fetchOne('SELECT COUNT(*) FROM article_state WHERE user_id = ? AND is_read = 1', [$reader->getId()->toBinary()]));
            self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM article_state WHERE user_id = ?', [$other->getId()->toBinary()]));
            $toast = $client->followRedirect()->filter('.toast[role="status"]');
            self::assertCount(1, $toast, 'Flash messages are announced through role="status" (the browser tests rely on it).');
            self::assertStringContainsString('3 article(s) marqué(s) comme lu(s).', $toast->text());
        } finally {
            $db->executeStatement('DELETE FROM app_user WHERE id IN (?, ?)', [$reader->getId()->toBinary(), $other->getId()->toBinary()]);
            $db->executeStatement('DELETE FROM feed WHERE id = ?', [$feed->getId()->toBinary()]);
        }
    }
}
