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

    public function testItIsClearedOnlyAfterTwentyFourHoursOfRecovery(): void
    {
        $incident = new FeedIncident(new Feed('https://example.test/recovered'), new \RuntimeException('Failure'));
        $recoveredAt = new \DateTimeImmutable('2026-10-25 10:00:00 UTC');
        $incident->markRecoveredAt($recoveredAt);

        self::assertFalse($incident->shouldBeClearedAt($recoveredAt->modify('+23 hours 59 minutes')));
        self::assertTrue($incident->shouldBeClearedAt($recoveredAt->modify('+24 hours')));

        $incident->report(new \RuntimeException('Failure again'));
        self::assertFalse($incident->shouldBeClearedAt($recoveredAt->modify('+48 hours')));
    }
}
