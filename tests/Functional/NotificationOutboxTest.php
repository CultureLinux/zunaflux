<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Application\Feed\CollectFeed;
use App\Application\Notification\DigestQueue;
use App\Application\Notification\NotificationOutbox;
use App\Application\Notification\RelayNotificationOutbox;
use App\Domain\Feed\FeedFetcherInterface;
use App\Domain\Feed\FeedParser;
use App\Domain\Feed\FetchedFeed;
use App\Entity\Feed;
use App\Entity\PushSubscription;
use App\Entity\Subscription;
use App\Entity\User;
use App\Message\NotifyFeedMessage;
use App\MessageHandler\NotifyFeedHandler;
use App\Tests\Support\FrozenClock;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\RecoverableExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Uid\Uuid;

final class NotificationOutboxTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $db;
    private Feed $feed;
    private FrozenClock $clock;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->db = $this->em->getConnection();
        $this->clock = new FrozenClock(new \DateTimeImmutable('2026-11-15 12:00:00 UTC'));
        $this->feed = new Feed('https://example.test/outbox-'.bin2hex(random_bytes(8)));
        $this->em->persist($this->feed);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        try {
            $this->db->executeStatement('DELETE FROM feed WHERE id = ?', [$this->feed->getId()->toBinary()]);
        } finally {
            parent::tearDown();
        }
    }

    public function testCollectionStoresArticlesAndOneDurableEventWithoutAnyBus(): void
    {
        $collector = $this->collector('<item><guid>first</guid><title>Article</title></item>');
        self::assertSame(1, $collector->collect($this->feed));
        self::assertSame(1, $this->articleCount());
        $rows = $this->pending();
        self::assertCount(1, $rows);
        self::assertSame(1, (int) $rows[0]['article_count']);

        self::assertSame(0, $collector->collect($this->feed));
        self::assertCount(1, $this->pending());
    }

    public function testFailedArticleFlushRollsBackTheEventAndFeedValidators(): void
    {
        // Two distinct ORM entities with the same source identity fail the real
        // MariaDB unique constraint after the outbox INSERT has been attempted.
        $collector = $this->collector('<item><guid>duplicate</guid></item><item><guid>duplicate</guid></item>');
        try {
            $collector->collect($this->feed);
            self::fail('The duplicate source identity must fail the transaction.');
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
            self::assertFalse($this->em->isOpen());
        }
        self::assertSame(0, $this->articleCount());
        self::assertSame([], $this->pending());
        self::assertNull($this->db->fetchOne('SELECT etag FROM feed WHERE id = ?', [$this->feed->getId()->toBinary()]));
        self::assertFalse($this->db->isTransactionActive());
    }

    public function testAnEmptyOrNotModifiedFeedDoesNotCreateAnEvent(): void
    {
        self::assertSame(0, $this->collector('')->collect($this->feed));
        $fetcher = new class implements FeedFetcherInterface {
            public function fetch(string $url, ?string $etag = null, ?string $lastModified = null): FetchedFeed
            {
                return new FetchedFeed(304, null, 'unchanged', null, $url);
            }
        };
        $collector = new CollectFeed($this->em, $fetcher, new FeedParser(), new NotificationOutbox($this->db, $this->clock));
        self::assertSame(0, $collector->collect($this->feed));
        self::assertSame([], $this->pending());
    }

    public function testAcceptedDispatchThenInterruptionCanBeReplayedWithoutDoubleCounting(): void
    {
        $user = new User('outbox-'.bin2hex(random_bytes(8)).'@example.test');
        $subscription = new Subscription($user, $this->feed);
        $device = new PushSubscription($user, 'https://fcm.googleapis.com/fcm/send/'.bin2hex(random_bytes(8)), 'key', 'auth');
        foreach ([$user, $subscription, $device] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        try {
            $this->collector('<item><guid>first</guid></item>')->collect($this->feed);
            $bus = $this->recordingBus(true);
            $relay = new RelayNotificationOutbox($this->db, $bus, new NullLogger());
            try {
                $relay->dispatchPending(1, $this->feed->getId());
                self::fail('The simulated interruption must escape the relay.');
            } catch (\RuntimeException $error) {
                self::assertSame('Interrupted after acceptance.', $error->getMessage());
            }
            self::assertCount(1, $this->pending());
            self::assertFalse($this->db->isTransactionActive());

            $bus->interrupt = false;
            self::assertSame(1, $relay->dispatchPending(1, $this->feed->getId()));
            self::assertSame([], $this->pending());
            self::assertCount(2, $bus->messages);
            self::assertSame($bus->messages[0]->eventId, $bus->messages[1]->eventId);

            // Both accepted copies are handled through the real digest path.
            $queue = new DigestQueue($this->db, $this->clock);
            $handler = new NotifyFeedHandler($this->em, $queue, new NullLogger(), $this->clock);
            foreach ($bus->messages as $message) {
                $handler($message);
            }
            self::assertSame(1, $queue->pending($user->getId(), 0)['count']);
            self::assertSame(0, $relay->dispatchPending(1, $this->feed->getId()));
        } finally {
            $this->db->executeStatement('DELETE FROM app_user WHERE id = ?', [$user->getId()->toBinary()]);
        }
    }

    public function testAConcurrentRelaySkipsLockedRowsAndLaterRecoversThem(): void
    {
        $outbox = new NotificationOutbox($this->db, $this->clock);
        $first = new NotifyFeedMessage((string) $this->feed->getId(), 'articles', 2);
        $second = new NotifyFeedMessage((string) $this->feed->getId(), 'articles', 3);
        $outbox->add($first);
        $outbox->add($second);
        $other = DriverManager::getConnection($this->db->getParams());
        try {
            $other->beginTransaction();
            $other->fetchAssociative('SELECT event_id FROM notification_outbox WHERE event_id = ? FOR UPDATE', [Uuid::fromString($first->eventId)->toBinary()]);
            $bus = $this->recordingBus();
            $relay = new RelayNotificationOutbox($this->db, $bus, new NullLogger());
            self::assertSame(1, $relay->dispatchPending(10, $this->feed->getId()));
            self::assertCount(1, $bus->messages);
            self::assertSame($second->eventId, $bus->messages[0]->eventId);
            $other->commit();
            self::assertSame(1, $relay->dispatchPending(10, $this->feed->getId()));
            self::assertSame($first->eventId, $bus->messages[1]->eventId);
            self::assertSame([], $this->pending());
        } finally {
            if ($other->isTransactionActive()) {
                $other->rollBack();
            }
            $other->close();
        }
    }

    public function testNativeSerializationRetainsTheStableEventIdentity(): void
    {
        $message = new NotifyFeedMessage((string) $this->feed->getId(), 'articles', 4);
        $restored = unserialize(serialize($message), ['allowed_classes' => [NotifyFeedMessage::class]]);
        self::assertInstanceOf(NotifyFeedMessage::class, $restored);
        self::assertSame($message->eventId, $restored->eventId);
        self::assertSame($message->eventId, (new NotifyFeedMessage($message->feedId, 'articles', 4, $message->eventId))->eventId);
    }

    public function testBusyDigestDoesNotForceUnlimitedRetries(): void
    {
        $user = new User('busy-outbox-'.bin2hex(random_bytes(8)).'@example.test');
        $this->em->persist($user);
        $this->em->persist(new Subscription($user, $this->feed));
        $this->em->flush();
        $other = DriverManager::getConnection($this->db->getParams());
        $lock = 'zunaflux-digest-'.$user->getId();
        try {
            self::assertSame(1, (int) $other->fetchOne('SELECT GET_LOCK(?, 0)', [$lock]));
            $handler = new NotifyFeedHandler($this->em, new DigestQueue($this->db, $this->clock), new NullLogger(), $this->clock);
            try {
                $handler(new NotifyFeedMessage((string) $this->feed->getId(), 'articles', 1));
                self::fail('A busy account must defer processing.');
            } catch (\RuntimeException $error) {
                self::assertNotInstanceOf(RecoverableExceptionInterface::class, $error);
            }
        } finally {
            $other->fetchOne('SELECT RELEASE_LOCK(?)', [$lock]);
            $other->close();
            $this->db->executeStatement('DELETE FROM app_user WHERE id = ?', [$user->getId()->toBinary()]);
        }
    }

    public function testFailedTransportCanBeInspectedAndReplayedWithoutDuplicateDigest(): void
    {
        $user = new User('failed-outbox-'.bin2hex(random_bytes(8)).'@example.test');
        $device = new PushSubscription($user, 'https://fcm.googleapis.com/fcm/send/'.bin2hex(random_bytes(8)), 'key', 'auth');
        foreach ([$user, new Subscription($user, $this->feed), $device] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $id = null;
        try {
            $message = new NotifyFeedMessage((string) $this->feed->getId(), 'articles', 2);
            $queue = new DigestQueue($this->db, $this->clock);
            (new NotifyFeedHandler($this->em, $queue, new NullLogger(), $this->clock))($message);

            // Model a failure after partial handling: the persistent failed copy
            // carries the same event identity as the already-counted message.
            $failed = self::getContainer()->get('messenger.transport.failed');
            $stored = $failed->send(new Envelope($message, [new SentToFailureTransportStamp('async')]));
            $stamp = $stored->last(TransportMessageIdStamp::class);
            self::assertNotNull($stamp);
            $id = $stamp->getId();
            $copy = $failed->find($id);
            self::assertNotNull($copy);
            self::assertSame($message->eventId, $copy->getMessage()->eventId);

            $application = new Application(self::$kernel);
            $show = new CommandTester($application->find('messenger:failed:show'));
            self::assertSame(0, $show->execute(['id' => $id, '--transport' => 'failed'], ['interactive' => false]));
            $retry = new CommandTester($application->find('messenger:failed:retry'));
            self::assertSame(0, $retry->execute(['id' => [$id], '--transport' => 'failed', '--force' => true], ['interactive' => false]));
            self::assertNull($failed->find($id));
            self::assertSame(2, $queue->pending($user->getId(), 0)['count']);
        } finally {
            if (null !== $id) {
                $this->db->delete('messenger_messages', ['id' => $id, 'queue_name' => 'failed']);
            }
            $this->db->executeStatement('DELETE FROM app_user WHERE id = ?', [$user->getId()->toBinary()]);
        }
    }

    private function collector(string $items): CollectFeed
    {
        $xml = '<rss version="2.0"><channel><title>Outbox</title>'.$items.'</channel></rss>';
        $fetcher = new class($xml) implements FeedFetcherInterface {
            public function __construct(private readonly string $xml)
            {
            }

            public function fetch(string $url, ?string $etag = null, ?string $lastModified = null): FetchedFeed
            {
                return new FetchedFeed(200, $this->xml, 'new-etag', null, $url);
            }
        };
        return new CollectFeed($this->em, $fetcher, new FeedParser(), new NotificationOutbox($this->db, $this->clock));
    }

    /** @return list<array<string, mixed>> */
    private function pending(): array
    {
        return $this->db->fetchAllAssociative('SELECT * FROM notification_outbox WHERE feed_id = ?', [$this->feed->getId()->toBinary()]);
    }

    private function articleCount(): int
    {
        return (int) $this->db->fetchOne('SELECT COUNT(*) FROM article WHERE feed_id = ?', [$this->feed->getId()->toBinary()]);
    }

    private function recordingBus(bool $interrupt = false): MessageBusInterface
    {
        return new class($interrupt) implements MessageBusInterface {
            /** @var list<NotifyFeedMessage> */
            public array $messages = [];

            public function __construct(public bool $interrupt)
            {
            }

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                if (!$message instanceof NotifyFeedMessage) {
                    throw new \LogicException('Unexpected message.');
                }
                $this->messages[] = $message;
                if ($this->interrupt) {
                    throw new \RuntimeException('Interrupted after acceptance.');
                }
                return new Envelope($message, $stamps);
            }
        };
    }
}
