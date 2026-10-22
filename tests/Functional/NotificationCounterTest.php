<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Application\Notification\NotificationCounter;
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
            $today = (new \DateTimeImmutable())->format('Y-m-d');
            $before = (int) $connection->fetchOne('SELECT total FROM notification_daily_count WHERE day = ?', [$today]);
            $counter = new NotificationCounter($connection, new NullLogger());
            $counter->recordAccepted();
            $counter->recordAccepted();
            self::assertSame($before + 2, (int) $connection->fetchOne('SELECT total FROM notification_daily_count WHERE day = ?', [$today]));
        } finally {
            $connection->rollBack();
        }
    }
}
