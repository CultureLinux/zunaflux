<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Feed;
use App\Entity\FeedIncident;
use PHPUnit\Framework\TestCase;

final class FeedIncidentTest extends TestCase
{
    public function testItExposesTheFirstAndLastFailureTimes(): void
    {
        $incident = new FeedIncident(new Feed('https://example.test/feed'), new \RuntimeException('Initial failure'));
        $incident->report(new \RuntimeException('Repeated failure'));

        self::assertInstanceOf(\DateTimeImmutable::class, $incident->getFirstOccurredAt());
        self::assertInstanceOf(\DateTimeImmutable::class, $incident->getLastOccurredAt());
        self::assertSame(2, $incident->getOccurrences());
    }
}
