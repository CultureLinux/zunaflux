<?php

declare(strict_types=1);

namespace App\Tests\Functional\Application;

use App\Application\Feed\FeedSyncRunService;
use App\Entity\Category;
use App\Message\CollectFeedMessage;
use App\Tests\Support\FrozenClock;
use App\Tests\Support\RollbackKernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class FeedSyncRunServiceTest extends RollbackKernelTestCase
{
    private object $bus;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bus = new class () implements MessageBusInterface {
            /** @var list<CollectFeedMessage> */
            public array $messages = [];

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $this->messages[] = $message;

                return new Envelope($message);
            }
        };
    }

    public function testARunCreatesOnePendingEntryAndOneMessagePerDistinctFeed(): void
    {
        $first = $this->feed('first');
        $second = $this->feed('second');
        $this->em->flush();
        $service = new FeedSyncRunService($this->db, $this->bus, new FrozenClock(new \DateTimeImmutable('2030-02-03 04:05:06')));

        $run = $service->start([$first->getId(), $second->getId()->toBinary(), (string) $first->getId()]);

        self::assertSame('2030-02-03 04:05:06', $this->db->fetchOne('SELECT created_at FROM feed_sync_run WHERE id = ?', [$run->toBinary()]));
        self::assertSame(2, $this->scalar('SELECT COUNT(*) FROM feed_sync_run_entry WHERE run_id = ? AND status = ?', [$run->toBinary(), 'pending']));
        self::assertCount(2, $this->bus->messages);
        self::assertSame((string) $run, $this->bus->messages[0]->syncRunId);
        $entries = $service->entries($run);
        self::assertCount(2, $entries);
        self::assertFalse(FeedSyncRunService::isComplete($entries));
    }

    public function testEntriesCanBeRestrictedToAnAccountACategoryOrAFeed(): void
    {
        $owner = $this->user();
        $other = $this->user();
        $mine = $this->feed('mine');
        $inCategory = $this->feed('category');
        $theirs = $this->feed('theirs');
        $this->subscribe($owner, $mine);
        $categorised = $this->subscribe($owner, $inCategory);
        $this->subscribe($other, $theirs);
        $category = new Category($owner, 'Dev');
        $this->em->persist($category);
        $categorised->assignCategory($category);
        $this->em->flush();
        $service = new FeedSyncRunService($this->db, $this->bus);
        $run = $service->start([$mine->getId(), $inCategory->getId(), $theirs->getId()]);

        self::assertCount(3, $service->entries($run));
        self::assertEqualsCanonicalizing([$mine->getCanonicalUrl(), $inCategory->getCanonicalUrl()], array_column($service->entries($run, user: $owner->getId()), 'canonical_url'));
        self::assertSame([$inCategory->getCanonicalUrl()], array_column($service->entries($run, user: $owner->getId(), category: $category->getId()), 'canonical_url'));
        self::assertSame([$theirs->getCanonicalUrl()], array_column($service->entries($run, feed: $theirs->getId()), 'canonical_url'));
        self::assertSame([], $service->entries($run, user: $other->getId(), feed: $mine->getId()));
    }

    public function testARunIsCompleteOnceNothingIsPendingOrRunning(): void
    {
        self::assertTrue(FeedSyncRunService::isComplete([]));
        self::assertTrue(FeedSyncRunService::isComplete([['status' => 'collected'], ['status' => 'error'], ['status' => 'no_new_articles']]));
        self::assertFalse(FeedSyncRunService::isComplete([['status' => 'collected'], ['status' => 'running']]));
    }
}
