<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Application\Notification\DigestQueue;
use App\Entity\Feed;
use App\Entity\PushSubscription;
use App\Entity\Subscription;
use App\Entity\User;
use App\Tests\Support\FrozenClock;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class DigestQueueTest extends KernelTestCase
{
    private Connection $db;
    private DigestQueue $queue;
    private User $first;
    private User $other;
    private Subscription $subscription;
    private Subscription $otherSubscription;
    private PushSubscription $device;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $this->db = $em->getConnection();
        $this->db->beginTransaction();
        $suffix = bin2hex(random_bytes(8));
        $this->first = new User('cursor-'.$suffix.'@example.test');
        $this->other = new User('cursor-other-'.$suffix.'@example.test');
        $feed = new Feed('https://example.test/cursor-'.$suffix);
        $this->subscription = new Subscription($this->first, $feed);
        $this->otherSubscription = new Subscription($this->other, $feed);
        $this->device = new PushSubscription($this->first, 'https://fcm.googleapis.com/fcm/send/'.$suffix, 'key', 'auth');
        foreach ([$this->first, $this->other, $feed, $this->subscription, $this->otherSubscription, $this->device] as $entity) {
            $em->persist($entity);
        }
        $em->flush();
        $this->queue = new DigestQueue($this->db, new FrozenClock(new \DateTimeImmutable('2026-11-15 12:00:00 UTC')));
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->db) && $this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
        } finally {
            parent::tearDown();
        }
    }

    public function testCursorsCountOnlyTheAccountAndOnlyEventsAfterTheCursor(): void
    {
        $firstEvent = $this->add($this->subscription, 2, '2026-11-15 10:00:00 UTC');
        $otherEvent = $this->add($this->otherSubscription, 99, '2026-11-15 11:00:00 UTC');
        $lastEvent = $this->add($this->subscription, 3, '2026-11-15 12:00:00 UTC');
        $pending = $this->queue->pending($this->first->getId(), 0);
        self::assertSame(5, $pending['count']);
        self::assertSame($lastEvent, $pending['cursor']);
        self::assertSame('2026-11-15 10:00:00', $pending['first']->format('Y-m-d H:i:s'));
        $remaining = $this->queue->pending($this->first->getId(), $firstEvent);
        self::assertSame(3, $remaining['count']);
        self::assertSame($lastEvent, $remaining['cursor']);
        self::assertSame(['count' => 0, 'cursor' => 0, 'first' => null], $this->queue->pending($this->first->getId(), $lastEvent));
        self::assertSame(99, $this->queue->pending($this->other->getId(), 0)['count']);
        self::assertSame($otherEvent, $this->queue->pending($this->other->getId(), 0)['cursor']);
    }

    public function testPruningUsesTheGivenUtcBoundaryAndPreservesUnconsumedAndOtherAccountsEvents(): void
    {
        $oldConsumed = $this->add($this->subscription, 1, '2026-10-16 11:59:59 UTC');
        $boundary = $this->add($this->subscription, 2, '2026-10-16 12:00:00 UTC');
        $recent = $this->add($this->subscription, 3, '2026-11-15 10:00:00 UTC');
        $oldUnconsumed = $this->add($this->subscription, 4, '2026-10-01 10:00:00 UTC');
        $other = $this->add($this->otherSubscription, 5, '2026-10-01 10:00:00 UTC');
        $this->db->update('push_subscription', ['digest_cursor' => $recent], ['id' => $this->device->getId()->toBinary()]);
        // This instant is 12:00 UTC despite its non-UTC representation.
        $pruner = new DigestQueue($this->db, new FrozenClock(new \DateTimeImmutable('2036-01-01 00:00:00 UTC')));
        $pruner->prune($this->first->getId(), new \DateTimeImmutable('2026-11-15 13:00:00 Europe/Paris'));
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM notification_pending WHERE id = ?', [$oldConsumed]));
        foreach ([$boundary, $recent, $oldUnconsumed, $other] as $id) {
            self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM notification_pending WHERE id = ?', [$id]));
        }
    }

    public function testAccountLocksExcludeASecondConnectionWithoutBlockingAnotherAccount(): void
    {
        $otherConnection = DriverManager::getConnection($this->db->getParams());
        $otherQueue = new DigestQueue($otherConnection);
        try {
            self::assertTrue($this->queue->lock($this->first->getId()));
            self::assertFalse($otherQueue->lock($this->first->getId()));
            self::assertTrue($otherQueue->lock($this->other->getId()));
            $this->queue->unlock($this->first->getId());
            self::assertTrue($otherQueue->lock($this->first->getId()));
        } finally {
            $this->queue->unlock($this->first->getId());
            $otherQueue->unlock($this->first->getId());
            $otherQueue->unlock($this->other->getId());
            $otherConnection->close();
        }
    }

    private function add(Subscription $subscription, int $count, string $instant): int
    {
        $event = Uuid::v7();
        $this->queue->add($subscription->getId(), (string) $event, $count, new \DateTimeImmutable($instant));

        return (int) $this->db->fetchOne('SELECT id FROM notification_pending WHERE subscription_id = ? AND event_id = ?', [$subscription->getId()->toBinary(), $event->toBinary()]);
    }
}
