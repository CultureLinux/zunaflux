<?php

declare(strict_types=1);

namespace App\Application\Notification;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

final readonly class NotificationCounter
{
    public function __construct(private Connection $connection, private LoggerInterface $logger) {}

    public function recordAccepted(): void
    {
        try {
            $this->connection->executeStatement('INSERT INTO notification_daily_count (day, total) VALUES (?, 1) ON DUPLICATE KEY UPDATE total = total + 1', [(new \DateTimeImmutable())->format('Y-m-d')]);
        } catch (\Throwable) {
            $this->logger->error('Impossible de comptabiliser une notification envoyée.');
        }
    }
}
