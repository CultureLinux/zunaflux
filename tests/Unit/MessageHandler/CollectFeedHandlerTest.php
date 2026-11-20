<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Application\Feed\CollectFeed;
use App\Application\Notification\NotificationOutbox;
use App\Application\Notification\RelayNotificationOutbox;
use App\Domain\Feed\FeedFetcherInterface;
use App\Domain\Feed\FeedParser;
use App\Domain\Feed\FetchedFeed;
use App\Entity\Feed;
use App\Entity\FeedIncident;
use App\Entity\FeedIncidentOccurrence;
use App\Message\CollectFeedMessage;
use App\MessageHandler\CollectFeedHandler;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\MessageBus;

final class CollectFeedHandlerTest extends TestCase
{
    public function testATransientFailureIsRecordedOnceAndNeverRethrown(): void
    {
        $feed = new Feed('https://example.test/failing');
        $fetcher = new class () implements FeedFetcherInterface {
            public int $calls = 0;

            public function fetch(string $url, ?string $etag = null, ?string $lastModified = null): FetchedFeed
            {
                ++$this->calls;
                throw new \RuntimeException('Feed request returned HTTP 503.');
            }
        };
        $persisted = [];
        $flushes = 0;
        $handler = $this->handler($feed, $fetcher, lockAcquired: true, persisted: $persisted, flushes: $flushes);

        // Messenger would redeliver on an exception: the handler must return normally.
        $handler(new CollectFeedMessage((string) $feed->getId()));

        self::assertSame(1, $fetcher->calls);
        self::assertSame(1, $feed->getFailureCount());
        self::assertSame(1, $flushes);
        self::assertCount(1, array_filter($persisted, static fn (object $entity): bool => $entity instanceof FeedIncident));
        self::assertCount(1, array_filter($persisted, static fn (object $entity): bool => $entity instanceof FeedIncidentOccurrence));
        // First failure: one hour of backoff, not the 24 h cap reached by six redeliveries.
        self::assertFalse($feed->isCollectionDue(new \DateTimeImmutable('+30 minutes')));
        self::assertTrue($feed->isCollectionDue(new \DateTimeImmutable('+61 minutes')));
    }

    public function testAFeedAlreadyBeingCollectedIsSkippedWithoutRecordingAFailure(): void
    {
        $feed = new Feed('https://example.test/locked');
        $fetcher = new class () implements FeedFetcherInterface {
            public int $calls = 0;

            public function fetch(string $url, ?string $etag = null, ?string $lastModified = null): FetchedFeed
            {
                ++$this->calls;

                return new FetchedFeed(304, null, null, null, $url);
            }
        };
        $persisted = [];
        $flushes = 0;
        $handler = $this->handler($feed, $fetcher, lockAcquired: false, persisted: $persisted, flushes: $flushes);

        $handler(new CollectFeedMessage((string) $feed->getId()));

        self::assertSame(0, $fetcher->calls);
        self::assertSame(0, $feed->getFailureCount());
        self::assertSame([], $persisted);
        self::assertSame(0, $flushes);
    }

    /**
     * @param list<object> $persisted
     */
    private function handler(Feed $feed, FeedFetcherInterface $fetcher, bool $lockAcquired, array &$persisted, int &$flushes): CollectFeedHandler
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturn($lockAcquired ? 1 : 0);

        $feeds = $this->createStub(EntityRepository::class);
        $feeds->method('find')->willReturn($feed);
        $incidents = $this->createStub(EntityRepository::class);
        $incidents->method('findOneBy')->willReturn(null);

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);
        $entityManager->method('isOpen')->willReturn(true);
        $entityManager->method('getRepository')->willReturnCallback(static fn (string $class): EntityRepository => Feed::class === $class ? $feeds : $incidents);
        $entityManager->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });
        $entityManager->method('flush')->willReturnCallback(static function () use (&$flushes): void {
            ++$flushes;
        });

        return new CollectFeedHandler(
            $entityManager,
            new CollectFeed($entityManager, $fetcher, new FeedParser(), new NotificationOutbox($connection)),
            new RelayNotificationOutbox($connection, new MessageBus(), new NullLogger()),
            new NullLogger(),
        );
    }
}
