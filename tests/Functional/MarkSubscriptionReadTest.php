<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Application\Reading\MarkSubscriptionRead;
use App\Domain\Feed\ParsedFeedItem;
use App\Entity\Article;
use App\Entity\ArticleState;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class MarkSubscriptionReadTest extends KernelTestCase
{
    public function testServiceUsesSubscriptionOwnerAndLeavesOtherAccountsAndFeedsUntouched(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $db = $em->getConnection();
        $db->beginTransaction();
        try {
            $suffix = bin2hex(random_bytes(8));
            $owner = new User('mark-service-'.$suffix.'@example.test');
            $other = new User('mark-service-other-'.$suffix.'@example.test');
            $feed = new Feed('https://example.test/mark-service-'.$suffix);
            $emptyFeed = new Feed('https://example.test/mark-empty-'.$suffix);
            $subscription = new Subscription($owner, $feed);
            $emptySubscription = new Subscription($owner, $emptyFeed);
            $article = new Article($feed, new ParsedFeedItem('one', 'Article', null, null, null, null));
            $otherState = new ArticleState($other, $article);
            $otherState->setSaved(true);
            foreach ([$owner, $other, $feed, $emptyFeed, $subscription, $emptySubscription, new Subscription($other, $feed), $article, $otherState] as $entity) {
                $em->persist($entity);
            }
            $em->flush();
            $service = self::getContainer()->get(MarkSubscriptionRead::class);
            $service->mark($emptySubscription);
            self::assertNull($em->getRepository(ArticleState::class)->findOneBy(['user' => $owner, 'article' => $article]));

            $service->mark($subscription);
            $service->mark($subscription);
            $state = $em->getRepository(ArticleState::class)->findOneBy(['user' => $owner, 'article' => $article]);
            self::assertInstanceOf(ArticleState::class, $state);
            self::assertTrue($state->isRead());
            self::assertFalse($otherState->isRead());
            self::assertTrue($otherState->isSaved());
            self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM first_read_event WHERE user_id = ?', [$owner->getId()->toBinary()]));
        } finally {
            while ($db->isTransactionActive()) {
                $db->rollBack();
            }
        }
    }

    public function testMarkBeforeProcessesEveryMatchingArticleAcrossBatchesAndIsIdempotent(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $db = $em->getConnection();
        $db->beginTransaction();
        try {
            $suffix = bin2hex(random_bytes(8));
            $owner = new User('mark-before-'.$suffix.'@example.test');
            $feed = new Feed('https://example.test/mark-before-'.$suffix);
            $disabledFeed = new Feed('https://example.test/mark-before-disabled-'.$suffix);
            $disabled = new Subscription($owner, $disabledFeed);
            $disabled->disable();
            foreach ([$owner, $feed, $disabledFeed, new Subscription($owner, $feed), $disabled] as $entity) {
                $em->persist($entity);
            }
            $old = new \DateTimeImmutable('2020-01-01 00:00:00');
            $count = 1201;
            for ($i = 0; $i < $count; ++$i) {
                $em->persist(new Article($feed, new ParsedFeedItem('old-'.$i, 'Old '.$i, null, null, null, $old)));
            }
            $recent = new Article($feed, new ParsedFeedItem('recent', 'Recent', null, null, null, new \DateTimeImmutable('2030-01-01 00:00:00')));
            $hidden = new Article($disabledFeed, new ParsedFeedItem('hidden', 'Hidden', null, null, null, $old));
            $favorite = new Article($feed, new ParsedFeedItem('favorite', 'Favorite', null, null, null, $old));
            $favoriteState = new ArticleState($owner, $favorite);
            $favoriteState->setFavorite(true);
            foreach ([$recent, $hidden, $favorite, $favoriteState] as $entity) {
                $em->persist($entity);
            }
            $em->flush();
            $em->clear();

            $service = self::getContainer()->get(MarkSubscriptionRead::class);
            $before = new \DateTimeImmutable('2025-01-01 00:00:00');
            $owner = $em->getRepository(User::class)->find($owner->getId());
            self::assertSame($count + 1, $service->markBefore($owner, $before));
            self::assertSame(0, $service->markBefore($owner, $before));

            $userId = $owner->getId()->toBinary();
            self::assertSame($count + 1, (int) $db->fetchOne('SELECT COUNT(*) FROM article_state WHERE user_id = ? AND is_read = 1', [$userId]));
            self::assertSame($count + 1, (int) $db->fetchOne('SELECT COUNT(*) FROM first_read_event WHERE user_id = ?', [$userId]));
            self::assertSame($count + 1, (int) $db->fetchOne('SELECT SUM(first_reads) FROM reading_daily_stat WHERE user_id = ?', [$userId]));
            self::assertSame(1, (int) $db->fetchOne('SELECT is_favorite FROM article_state WHERE user_id = ? AND article_id = ?', [$userId, $favorite->getId()->toBinary()]));
            self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM article_state WHERE article_id IN (?, ?)', [$recent->getId()->toBinary(), $hidden->getId()->toBinary()]));
        } finally {
            while ($db->isTransactionActive()) {
                $db->rollBack();
            }
        }
    }
}
