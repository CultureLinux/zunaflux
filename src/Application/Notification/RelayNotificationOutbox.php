<?php

declare(strict_types=1);

namespace App\Application\Notification;

use App\Message\NotifyFeedMessage;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

final readonly class RelayNotificationOutbox
{
    public function __construct(
        private Connection $connection,
        private MessageBusInterface $bus,
        private LoggerInterface $logger,
    ) {
    }

    public function dispatchPending(int $limit = 100, ?Uuid $feed = null): int
    {
        if ($limit < 1 || $limit > 1000) {
            throw new \InvalidArgumentException('Outbox batch size must be between 1 and 1000.');
        }

        $dispatched = 0;
        try {
            while ($dispatched < $limit) {
                $found = $this->connection->transactional(function (Connection $connection) use ($feed): bool {
                    $where = null === $feed ? '' : ' WHERE feed_id = ?';
                    $row = $connection->fetchAssociative(
                        'SELECT event_id, feed_id, article_count FROM notification_outbox'.$where.' ORDER BY created_at, event_id LIMIT 1 FOR UPDATE SKIP LOCKED',
                        null === $feed ? [] : [$feed->toBinary()],
                    );
                    if (false === $row) {
                        return false;
                    }

                    $message = new NotifyFeedMessage(
                        (string) Uuid::fromBinary($row['feed_id']),
                        'articles',
                        (int) $row['article_count'],
                        (string) Uuid::fromBinary($row['event_id']),
                    );
                    $this->bus->dispatch($message);
                    // Delete only after dispatch succeeded. Rollback preserves the
                    // same event identity if acceptance preceded an interruption.
                    $connection->delete('notification_outbox', ['event_id' => $row['event_id']]);

                    return true;
                });
                if (!$found) {
                    break;
                }
                ++$dispatched;
            }
        } catch (\Throwable $error) {
            // Transport errors can contain credentials or endpoint tokens.
            $this->logger->error('Relais des notifications interrompu ; événements conservés pour reprise.', [
                'status' => 'outbox_retry_pending',
                'dispatched_count' => $dispatched,
            ]);
            throw $error;
        }

        return $dispatched;
    }
}
