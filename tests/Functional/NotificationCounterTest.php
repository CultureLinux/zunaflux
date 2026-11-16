<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Application\Notification\NotificationCounter;
use App\Tests\Support\FrozenClock;
use Doctrine\DBAL\Connection;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class NotificationCounterTest extends KernelTestCase
{
    public function testAcceptedNotificationsAreCountedPerDay(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        $connection->beginTransaction();
        try {
            $clock = new FrozenClock(new \DateTimeImmutable('2026-11-14 23:59:59 Europe/Paris'));
            $today = '2026-11-14';
            $before = (int) $connection->fetchOne('SELECT total FROM notification_daily_count WHERE day = ?', [$today]);
            $counter = new NotificationCounter($connection, new NullLogger(), $clock);
            $counter->recordAccepted();
            $counter->recordAccepted();
            self::assertSame($before + 2, (int) $connection->fetchOne('SELECT total FROM notification_daily_count WHERE day = ?', [$today]));
            $tomorrowBefore = (int) $connection->fetchOne('SELECT total FROM notification_daily_count WHERE day = ?', ['2026-11-15']);
            // It is still November 14 in UTC, but midnight has passed in the clock's timezone.
            $clock->set(new \DateTimeImmutable('2026-11-15 00:00:00 Europe/Paris'));
            $counter->recordAccepted();
            self::assertSame($tomorrowBefore + 1, (int) $connection->fetchOne('SELECT total FROM notification_daily_count WHERE day = ?', ['2026-11-15']));
            self::assertSame($before + 2, (int) $connection->fetchOne('SELECT total FROM notification_daily_count WHERE day = ?', [$today]));
        } finally {
            $connection->rollBack();
        }
    }
}
