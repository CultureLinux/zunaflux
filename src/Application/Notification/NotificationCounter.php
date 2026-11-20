<?php

declare(strict_types=1);

namespace App\Application\Notification;

use App\Application\Time\Clock;
use App\Application\Time\SystemClock;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

final readonly class NotificationCounter
{
    public function __construct(
        private Connection $connection,
        private LoggerInterface $logger,
        private Clock $clock = new SystemClock(),
    ) {
    }

    public function recordAccepted(): void
    {
        try {
            $this
                ->connection
                ->executeStatement(
                    'INSERT INTO notification_daily_count (day, total) VALUES (?, 1) ON DUPLICATE KEY UPDATE total = total + 1',
                    [$this->clock->now()->format('Y-m-d')],
                );
        } catch (\Throwable) {
            $this->logger->error('Impossible de comptabiliser une notification envoyée.');
        }
    }
}
