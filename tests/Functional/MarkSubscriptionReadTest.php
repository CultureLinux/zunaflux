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
}
