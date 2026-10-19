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
}
