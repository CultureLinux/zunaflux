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
}
