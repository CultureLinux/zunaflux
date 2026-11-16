<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Domain\Feed\ParsedFeedItem;
use App\Entity\Article;
use App\Entity\ArticleState;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SubscriptionReadBatchTest extends WebTestCase
{
    public function testMarkReadCrossesBatchBoundaryAndPreservesAccountsFavoritesAndFirstReadStatistics(): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $db = $em->getConnection();
        $suffix = bin2hex(random_bytes(8));
        $owner = new User('batch-'.$suffix.'@example.test');
        $other = new User('batch-other-'.$suffix.'@example.test');
        $feed = new Feed('https://example.test/batch-'.$suffix);
        $foreignFeed = new Feed('https://example.test/batch-foreign-'.$suffix);
        $subscription = new Subscription($owner, $feed);
        foreach ([$owner, $other, $feed, $foreignFeed, $subscription, new Subscription($other, $feed), new Subscription($owner, $foreignFeed)] as $entity) {
            $em->persist($entity);
        }
        $favoriteId = null;
        for ($index = 0; $index < 102; ++$index) {
            $article = new Article($feed, new ParsedFeedItem('batch-'.$index, 'Article '.$index, null, null, null, null));
            $em->persist($article);
            if ($index < 2) {
                $state = new ArticleState($owner, $article);
                if (0 === $index) {
                    $state->markRead();
                } else {
                    $state->setFavorite(true);
                    $favoriteId = $article->getId();
                }
                $em->persist($state);
            }
        }
        $em->persist(new Article($foreignFeed, new ParsedFeedItem('foreign', 'Other feed', null, null, null, null)));
        $em->flush();
        try {
            $client->loginUser($owner);
            $crawler = $client->request('GET', '/articles');
            $path = '/subscriptions/'.$subscription->getId().'/mark-read';
            $token = $crawler->filter('form[action="'.$path.'"] input[name="_token"]')->first()->attr('value');
            for ($attempt = 0; $attempt < 2; ++$attempt) {
                $client->request('POST', $path, ['_token' => $token]);
                self::assertResponseRedirects('/articles?subscription='.$subscription->getId().'&read=0');
                self::assertSame(102, (int) $db->fetchOne('SELECT COUNT(*) FROM article_state WHERE user_id = ? AND is_read = 1', [$owner->getId()->toBinary()]));
                self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM article_state WHERE user_id = ?', [$other->getId()->toBinary()]));
                self::assertSame(101, (int) $db->fetchOne('SELECT COUNT(*) FROM first_read_event WHERE user_id = ?', [$owner->getId()->toBinary()]));
                self::assertSame(101, (int) $db->fetchOne('SELECT SUM(first_reads) FROM reading_daily_stat WHERE user_id = ?', [$owner->getId()->toBinary()]));
            }
            self::assertSame(1, (int) $db->fetchOne('SELECT is_favorite FROM article_state WHERE user_id = ? AND article_id = ?', [$owner->getId()->toBinary(), $favoriteId->toBinary()]));
            self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM article_state st INNER JOIN article a ON a.id = st.article_id WHERE a.feed_id = ?', [$foreignFeed->getId()->toBinary()]));
        } finally {
            $db->executeStatement('DELETE FROM app_user WHERE id IN (?, ?)', [$owner->getId()->toBinary(), $other->getId()->toBinary()]);
            $db->executeStatement('DELETE FROM feed WHERE id IN (?, ?)', [$feed->getId()->toBinary(), $foreignFeed->getId()->toBinary()]);
        }
    }
}
