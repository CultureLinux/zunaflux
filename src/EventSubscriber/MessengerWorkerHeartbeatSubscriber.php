<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Doctrine\DBAL\Connection;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;

final class MessengerWorkerHeartbeatSubscriber implements EventSubscriberInterface
{
    private int $lastRecordedAt = 0;

    public function __construct(private readonly Connection $connection) {}

    public static function getSubscribedEvents(): array
    {
        return [WorkerRunningEvent::class => 'record'];
    }

    public function record(): void
    {
        if ($this->lastRecordedAt > time() - 30) return;
        $this->connection->executeStatement('INSERT INTO messenger_worker_heartbeat (name, last_seen_at) VALUES (?, NOW()) ON DUPLICATE KEY UPDATE last_seen_at = VALUES(last_seen_at)', ['async']);
        $this->lastRecordedAt = time();
    }
}
