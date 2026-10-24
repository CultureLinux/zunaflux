<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Feed;
use App\Entity\FeedIncident;
use App\Entity\FeedIncidentOccurrence;
use PHPUnit\Framework\TestCase;

final class FeedIncidentOccurrenceTest extends TestCase
{
    public function testItKeepsTheFailureTimeAndMessage(): void
    {
        $incident = new FeedIncident(new Feed('https://example.test/history'), new \RuntimeException('Original failure'));
        $occurredAt = new \DateTimeImmutable('2026-10-25 12:34:00 UTC');
        $occurrence = new FeedIncidentOccurrence($incident, new \RuntimeException('Feed request returned HTTP 520.'), $occurredAt);

        self::assertSame($incident, $occurrence->getIncident());
        self::assertSame('Feed request returned HTTP 520.', $occurrence->getMessage());
        self::assertSame($occurredAt, $occurrence->getOccurredAt());
    }
}
