<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Feed;
use PHPUnit\Framework\TestCase;

final class FeedTest extends TestCase
{
    public function testItCanonicalizesAnHttpUrlForSharedFeedUniqueness(): void
    {
        $feed = new Feed('HTTPS://Example.TEST:443/news#latest');

        self::assertSame('https://example.test/news', $feed->getCanonicalUrl());
    }

    public function testItRejectsUnsupportedFeedSchemes(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Feed('file:///etc/passwd');
    }

    public function testItRemembersValidatorsReturnedByACollection(): void
    {
        $feed = new Feed('https://example.test/feed.xml');

        $feed->recordFetchValidators('"version-42"', 'Sun, 19 Oct 2026 08:00:00 GMT');

        self::assertSame('"version-42"', $feed->getEtag());
        self::assertSame('Sun, 19 Oct 2026 08:00:00 GMT', $feed->getLastModified());
    }

    public function testItDefersCollectionWithAnExponentialBackoffAfterFailures(): void
    {
        $feed = new Feed('https://example.test/feed.xml');
        $now = new \DateTimeImmutable('2026-10-24 12:00:00');

        self::assertTrue($feed->isCollectionDue($now));

        $feed->recordFailure(new \RuntimeException('First failure'), $now);
        self::assertFalse($feed->isCollectionDue($now->modify('+59 minutes')));
        self::assertTrue($feed->isCollectionDue($now->modify('+1 hour')));

        $feed->recordFailure(new \RuntimeException('Second failure'), $now->modify('+1 hour'));
        self::assertFalse($feed->isCollectionDue($now->modify('+2 hours 59 minutes')));
        self::assertTrue($feed->isCollectionDue($now->modify('+3 hours')));
    }

    public function testItCapsCollectionBackoffAndClearsItAfterASuccess(): void
    {
        $feed = new Feed('https://example.test/feed.xml');
        $now = new \DateTimeImmutable('2026-10-24 12:00:00');

        for ($i = 0; $i < 8; ++$i) {
            $feed->recordFailure(new \RuntimeException('Failure'), $now);
        }

        self::assertFalse($feed->isCollectionDue($now->modify('+23 hours 59 minutes')));
        self::assertTrue($feed->isCollectionDue($now->modify('+24 hours')));

        $feed->recordSuccess();
        self::assertTrue($feed->isCollectionDue($now));
    }
}
