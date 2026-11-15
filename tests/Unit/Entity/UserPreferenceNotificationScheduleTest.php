<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\User;
use App\Entity\UserPreference;
use PHPUnit\Framework\TestCase;

final class UserPreferenceNotificationScheduleTest extends TestCase
{
    public function testIntervalsStartFromThePreviousDelivery(): void
    {
        $preference = new UserPreference(new User('schedule@example.test'));
        $after = new \DateTimeImmutable('2026-11-14 07:15:00 UTC');
        foreach ([1, 2, 3, 4, 5, 6, 12] as $hours) {
            $preference->setNotificationSchedule('interval', $hours, []);
            self::assertSame($after->getTimestamp() + $hours * 3600, $preference->nextNotificationAfter($after)->getTimestamp());
        }
    }

    public function testFixedHoursUseTheAccountTimezoneAndRollOverToTomorrow(): void
    {
        $preference = new UserPreference(new User('schedule@example.test'));
        $preference->setNotificationSchedule('fixed', 1, [18, 8, 8]);
        self::assertSame([8, 18], $preference->getNotificationTimes());
        self::assertSame('2026-11-14T17:00:00+00:00', $preference->nextNotificationAfter(new \DateTimeImmutable('2026-11-14 07:00:00 UTC'))->format('c'));
        self::assertSame('2026-11-15T07:00:00+00:00', $preference->nextNotificationAfter(new \DateTimeImmutable('2026-11-14 17:00:00 UTC'))->format('c'));
    }

    public function testOneSlotMeansDailyAndFollowsDaylightSavingTime(): void
    {
        $preference = new UserPreference(new User('schedule@example.test'));
        $preference->setNotificationSchedule('fixed', 1, [8]);
        self::assertSame('2026-03-29T06:00:00+00:00', $preference->nextNotificationAfter(new \DateTimeImmutable('2026-03-28 07:00:00 UTC'))->format('c'));
        self::assertSame('2026-10-25T07:00:00+00:00', $preference->nextNotificationAfter(new \DateTimeImmutable('2026-10-24 06:00:00 UTC'))->format('c'));
    }

    public function testQuietHoursDelayAnOverdueDigestWithoutLosingItsDueState(): void
    {
        $preference = new UserPreference(new User('schedule@example.test'));
        $preference->update(['quiet_start' => '22:00', 'quiet_end' => '08:00']);
        $after = new \DateTimeImmutable('2026-11-14 20:00:00 UTC');
        self::assertFalse($preference->isNotificationDue($after, new \DateTimeImmutable('2026-11-14 21:00:00 UTC')));
        self::assertTrue($preference->isNotificationDue($after, new \DateTimeImmutable('2026-11-15 07:00:00 UTC')));
    }

    public function testFixedScheduleWaitsForTheNextSlotWhenTheEarlierSlotWasEmpty(): void
    {
        $preference = new UserPreference(new User('schedule@example.test'));
        $preference->update(['timezone' => 'UTC']);
        $preference->setNotificationSchedule('fixed', 1, [8, 12, 18, 22]);
        $firstNewArticle = new \DateTimeImmutable('2026-11-14 09:00:00 UTC');
        self::assertFalse($preference->isNotificationDue($firstNewArticle, new \DateTimeImmutable('2026-11-14 09:01:00 UTC')));
        self::assertTrue($preference->isNotificationDue($firstNewArticle, new \DateTimeImmutable('2026-11-14 12:00:00 UTC')));
    }

    public function testQuietHourBoundariesAreLocalAndEndIsExclusive(): void
    {
        $preference = new UserPreference(new User('quiet-boundary@example.test'));
        $preference->update(['timezone' => 'Europe/Paris', 'quiet_start' => '22:00', 'quiet_end' => '08:00']);
        foreach ([
            ['2026-11-14 20:59:59 UTC', true],
            ['2026-11-14 21:00:00 UTC', false],
            ['2026-11-15 06:59:59 UTC', false],
            ['2026-11-15 07:00:00 UTC', true],
        ] as [$instant, $allowed]) {
            self::assertSame($allowed, $preference->allowsPushAt(new \DateTimeImmutable($instant)), $instant);
        }
        $preference->update(['quiet_start' => '12:00', 'quiet_end' => '14:00']);
        self::assertFalse($preference->allowsPushAt(new \DateTimeImmutable('2026-11-15 11:00:00 UTC')));
        self::assertTrue($preference->allowsPushAt(new \DateTimeImmutable('2026-11-15 13:00:00 UTC')));
    }

    public function testIntervalMeasuresElapsedHoursAcrossBothClockChanges(): void
    {
        $preference = new UserPreference(new User('dst-interval@example.test'));
        $preference->update(['timezone' => 'Europe/Paris']);
        $preference->setNotificationSchedule('interval', 1, []);
        foreach ([
            ['2026-03-29 01:30:00 Europe/Paris', '2026-03-29T01:30:00+00:00'],
            ['2026-10-25 00:30:00 UTC', '2026-10-25T01:30:00+00:00'],
            ['2026-10-25 01:30:00 UTC', '2026-10-25T02:30:00+00:00'],
        ] as [$after, $expected]) {
            $instant = new \DateTimeImmutable($after);
            $next = $preference->nextNotificationAfter($instant);
            self::assertSame($expected, $next->format('c'));
            self::assertSame(3600, $next->getTimestamp() - $instant->getTimestamp());
        }
    }

    public function testFixedSchedulesRespectDatesOnBothSidesOfUtc(): void
    {
        $preference = new UserPreference(new User('timezone-date@example.test'));
        $preference->setNotificationSchedule('fixed', 1, [8]);
        foreach ([
            ['Asia/Tokyo', '2026-11-14 22:59:59 UTC', '2026-11-14T23:00:00+00:00'],
            ['Asia/Tokyo', '2026-11-14 23:00:00 UTC', '2026-11-15T23:00:00+00:00'],
            ['Pacific/Honolulu', '2026-11-14 19:00:00 UTC', '2026-11-15T18:00:00+00:00'],
        ] as [$timezone, $after, $expected]) {
            $preference->update(['timezone' => $timezone]);
            self::assertSame($expected, $preference->nextNotificationAfter(new \DateTimeImmutable($after))->format('c'));
        }
    }

    public function testFixedDailySlotIsNotRepeatedAfterDeliveryOnDstDays(): void
    {
        $preference = new UserPreference(new User('dst-daily@example.test'));
        $preference->update(['timezone' => 'Europe/Paris']);
        $preference->setNotificationSchedule('fixed', 1, [8]);
        self::assertSame('2026-03-30T06:00:00+00:00', $preference->nextNotificationAfter(new \DateTimeImmutable('2026-03-29 06:00:00 UTC'))->format('c'));
        self::assertSame('2026-10-26T07:00:00+00:00', $preference->nextNotificationAfter(new \DateTimeImmutable('2026-10-25 07:00:00 UTC'))->format('c'));
    }

    public function testQuietHoursCoverBothOccurrencesOfTheRepeatedHour(): void
    {
        $preference = new UserPreference(new User('dst-quiet@example.test'));
        $preference->update(['timezone' => 'Europe/Paris', 'quiet_start' => '22:00', 'quiet_end' => '08:00']);
        foreach ([
            '2026-03-29 00:59:59 UTC',
            '2026-03-29 01:00:00 UTC',
            '2026-10-25 00:30:00 UTC',
            '2026-10-25 01:30:00 UTC',
        ] as $instant) {
            self::assertFalse($preference->allowsPushAt(new \DateTimeImmutable($instant)), $instant);
        }
        self::assertTrue($preference->allowsPushAt(new \DateTimeImmutable('2026-03-29 06:00:00 UTC')));
        self::assertTrue($preference->allowsPushAt(new \DateTimeImmutable('2026-10-25 07:00:00 UTC')));
    }

    public function testInvalidScheduleDoesNotPartiallyChangePreferences(): void
    {
        $preference = new UserPreference(new User('schedule@example.test'));
        foreach ([['interval', 7, []], ['fixed', 1, []], ['fixed', 1, [9]], ['fixed', 1, ['8']], ['unknown', 1, [8]]] as [$mode, $interval, $times]) {
            try {
                $preference->setNotificationSchedule($mode, $interval, $times);
                self::fail('Invalid schedule accepted.');
            } catch (\InvalidArgumentException) {
                self::assertSame('interval', $preference->getNotificationMode());
                self::assertSame(1, $preference->getNotificationInterval());
            }
        }
    }
}
