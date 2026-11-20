<?php

declare(strict_types=1);

namespace App\Tests\Functional\Application;

use App\Application\Exception\AlreadySubscribed;
use App\Application\Exception\InvalidFeedAddress;
use App\Application\Subscription\SubscriptionEditor;
use App\Application\Subscription\SubscriptionRemoval;
use App\Entity\ArticleState;
use App\Entity\Category;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Tests\Support\RollbackKernelTestCase;

final class SubscriptionLifecycleTest extends RollbackKernelTestCase
{
    public function testEditingMovesTheSubscriptionToANewFeedAndDropsItsQueuedNotifications(): void
    {
        $user = $this->user();
        $old = $this->feed('old');
        $subscription = $this->subscribe($user, $old);
        $category = new Category($user, 'Dev');
        $this->em->persist($category);
        $this->em->flush();
        $eventId = random_bytes(16);
        $this->db->executeStatement('INSERT INTO notification_pending (subscription_id, event_id, article_count, created_at) VALUES (?, ?, 2, NOW())', [$subscription->getId()->toBinary(), $eventId]);
        $newUrl = 'https://example.test/new-'.bin2hex(random_bytes(6));

        self::getContainer()->get(SubscriptionEditor::class)->update($subscription, $newUrl, $category, 'Mon titre', true);

        $this->em->clear();
        $saved = $this->em->getRepository(Subscription::class)->find($subscription->getId());
        self::assertSame($newUrl, $saved->getFeed()->getCanonicalUrl());
        self::assertSame('Mon titre', $saved->getDisplayTitle());
        self::assertSame('Dev', $saved->getCategory()?->getName());
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM notification_pending WHERE subscription_id = ?', [$subscription->getId()->toBinary()]));
        self::assertNotNull($this->em->getRepository(Feed::class)->find($old->getId()), 'The previous shared feed is kept.');
    }

    public function testEditingWithoutMovingKeepsQueuedNotificationsWhileDisablingDropsThem(): void
    {
        $user = $this->user();
        $feed = $this->feed();
        $subscription = $this->subscribe($user, $feed);
        $this->em->flush();
        $this->db->executeStatement('INSERT INTO notification_pending (subscription_id, event_id, article_count, created_at) VALUES (?, ?, 1, NOW())', [$subscription->getId()->toBinary(), random_bytes(16)]);
        $editor = self::getContainer()->get(SubscriptionEditor::class);

        $editor->update($subscription, $feed->getCanonicalUrl(), null, null, true);
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM notification_pending WHERE subscription_id = ?', [$subscription->getId()->toBinary()]));

        $editor->update($subscription, $feed->getCanonicalUrl(), null, null, false);
        self::assertFalse($subscription->isEnabled());
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM notification_pending WHERE subscription_id = ?', [$subscription->getId()->toBinary()]));
    }

    public function testEditingRefusesAnAddressAlreadyFollowedAndUnusableAddresses(): void
    {
        $user = $this->user();
        $first = $this->feed('first');
        $second = $this->feed('second');
        $mine = $this->subscribe($user, $first);
        $this->subscribe($user, $second);
        $this->em->flush();
        $editor = self::getContainer()->get(SubscriptionEditor::class);

        foreach ([[$second->getCanonicalUrl(), AlreadySubscribed::class], ['ftp://example.test/feed', InvalidFeedAddress::class], ['https://user:pass@example.test/feed', InvalidFeedAddress::class], ['not a url', InvalidFeedAddress::class]] as [$url, $expected]) {
            try {
                $editor->update($mine, $url, null, null, true);
                self::fail('The address '.$url.' must be refused.');
            } catch (\InvalidArgumentException $exception) {
                self::assertInstanceOf($expected, $exception, $url);
            }
        }
        self::assertSame($first->getCanonicalUrl(), $mine->getFeed()->getCanonicalUrl());
    }

    public function testRemovingASubscriptionDeletesTheAccountStatesOnly(): void
    {
        $user = $this->user();
        $other = $this->user();
        $feed = $this->feed();
        $article = $this->article($feed);
        $subscription = $this->subscribe($user, $feed);
        $this->subscribe($other, $feed);
        $this->em->persist(new ArticleState($user, $article));
        $this->em->persist(new ArticleState($other, $article));
        $this->em->flush();

        self::getContainer()->get(SubscriptionRemoval::class)->remove($subscription);

        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM subscription WHERE user_id = ?', [$user->getId()->toBinary()]));
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM article_state WHERE user_id = ?', [$user->getId()->toBinary()]));
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM article_state WHERE user_id = ?', [$other->getId()->toBinary()]));
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM feed WHERE id = ?', [$feed->getId()->toBinary()]));
    }

    public function testRemovingACategoryRemovesItsSubscriptionsAndStatesButNotTheOthers(): void
    {
        $user = $this->user();
        $inside = $this->feed('inside');
        $outside = $this->feed('outside');
        $article = $this->article($inside);
        $kept = $this->article($outside);
        $category = new Category($user, 'Temporaire');
        $this->em->persist($category);
        $this->subscribe($user, $inside)->assignCategory($category);
        $this->subscribe($user, $outside);
        $this->em->persist(new ArticleState($user, $article));
        $this->em->persist(new ArticleState($user, $kept));
        $this->em->flush();

        self::getContainer()->get(SubscriptionRemoval::class)->removeCategory($user, $category);

        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM category WHERE id = ?', [$category->getId()->toBinary()]));
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM subscription WHERE user_id = ?', [$user->getId()->toBinary()]));
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM article_state WHERE user_id = ?', [$user->getId()->toBinary()]));
    }
}
