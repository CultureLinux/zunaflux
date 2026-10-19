<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\ReadingDailyStat;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class ReadingDailyStatTest extends TestCase
{
    /** Removing the daily aggregation increment must make this contract fail. */
    public function testItAccumulatesFirstReadsForOneDayAndCategorySnapshot(): void
    {
        $stat = new ReadingDailyStat(new User('reader@example.test'), new \DateTimeImmutable('2026-10-19'), 'Tech');

        $stat->increment();
        $stat->increment();

        self::assertSame(2, $stat->getFirstReads());
        self::assertSame('Tech', $stat->getCategorySnapshot());
    }
}
