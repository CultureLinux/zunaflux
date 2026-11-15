<?php

declare(strict_types=1);

namespace App\Application\Notification;

use App\Application\Time\Clock;
use App\Application\Time\SystemClock;
use App\Message\NotifyFeedMessage;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final readonly class NotificationOutbox
{
    public function __construct(private Connection $connection, private Clock $clock = new SystemClock())
    {
    }

    public function add(NotifyFeedMessage $message): void
    {
        if ('articles' !== $message->kind || $message->count < 1) {
            throw new \InvalidArgumentException('Only non-empty article notifications belong in the outbox.');
        }

        $this->connection->insert('notification_outbox', [
            'event_id' => Uuid::fromString($message->eventId)->toBinary(),
            'feed_id' => Uuid::fromString($message->feedId)->toBinary(),
            'article_count' => $message->count,
            'created_at' => $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        ]);
    }
}
