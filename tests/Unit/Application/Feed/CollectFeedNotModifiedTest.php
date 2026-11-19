<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Feed;

use App\Application\Feed\CollectFeed;
use App\Application\Feed\FeedCollectionInProgress;
use App\Application\Notification\NotificationOutbox;
use App\Domain\Feed\FeedFetcherInterface;
use App\Domain\Feed\FeedParser;
use App\Domain\Feed\FetchedFeed;
use App\Entity\Feed;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class CollectFeedNotModifiedTest extends TestCase
{
    public function testANotModifiedResponseWithoutValidatorsKeepsTheStoredOnes(): void
    {
        $feed = new Feed('https://example.test/feed');
        $feed->recordFetchValidators('"v1"', 'Wed, 18 Nov 2026 10:00:00 GMT');

        $collected = $this->collector($feed, new FetchedFeed(304, null, null, null, 'https://example.test/feed'), true);

        self::assertSame(0, $collected->collect($feed));
        self::assertSame('"v1"', $feed->getEtag());
        self::assertSame('Wed, 18 Nov 2026 10:00:00 GMT', $feed->getLastModified());
    }

    public function testANotModifiedResponseCanRefreshTheValidators(): void
    {
        $feed = new Feed('https://example.test/feed');
        $feed->recordFetchValidators('"v1"', null);

        $this->collector($feed, new FetchedFeed(304, null, '"v2"', null, 'https://example.test/feed'), true)->collect($feed);

        self::assertSame('"v2"', $feed->getEtag());
    }

    public function testAFullResponseReplacesTheValidatorsEvenWithNull(): void
    {
        $feed = new Feed('https://example.test/feed');
        $feed->recordFetchValidators('"v1"', 'old');

        $this->collector($feed, new FetchedFeed(200, '<rss version="2.0"><channel><title>T</title></channel></rss>', null, null, 'https://example.test/feed'), true)->collect($feed);

        self::assertNull($feed->getEtag());
        self::assertNull($feed->getLastModified());
    }

    public function testACollectionAlreadyRunningIsRefusedBeforeAnyNetworkCall(): void
    {
        $feed = new Feed('https://example.test/feed');
        $fetcher = new class implements FeedFetcherInterface {
            public int $calls = 0;

            public function fetch(string $url, ?string $etag = null, ?string $lastModified = null): FetchedFeed
            {
                ++$this->calls;

                return new FetchedFeed(304, null, null, null, $url);
            }
        };

        try {
            $this->collector($feed, null, false, $fetcher)->collect($feed);
            self::fail('A held lock must refuse the collection.');
        } catch (FeedCollectionInProgress) {
            self::assertSame(0, $fetcher->calls);
        }
    }

    private function collector(Feed $feed, ?FetchedFeed $response, bool $lockAcquired, ?FeedFetcherInterface $fetcher = null): CollectFeed
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturn($lockAcquired ? 1 : 0);
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);
        $entityManager->method('wrapInTransaction')->willReturnCallback(static fn (callable $callback): mixed => $callback($entityManager));

        $fetcher ??= new class($response) implements FeedFetcherInterface {
            public function __construct(private readonly ?FetchedFeed $response)
            {
            }

            public function fetch(string $url, ?string $etag = null, ?string $lastModified = null): FetchedFeed
            {
                return $this->response ?? throw new \LogicException('No response configured.');
            }
        };

        return new CollectFeed($entityManager, $fetcher, new FeedParser(), new NotificationOutbox($connection));
    }
}
