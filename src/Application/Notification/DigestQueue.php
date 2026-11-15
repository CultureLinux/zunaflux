<?php

declare(strict_types=1);

namespace App\Application\Notification;

use App\Application\Time\Clock;
use App\Application\Time\SystemClock;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final readonly class DigestQueue
{
    public function __construct(private Connection $connection, private Clock $clock = new SystemClock())
    {
    }

    public function lock(Uuid $user): bool
    {
        return 1 === (int) $this->connection->fetchOne('SELECT GET_LOCK(?, 0)', ['zunaflux-digest-'.$user]);
    }

    public function unlock(Uuid $user): void
    {
        $this->connection->fetchOne('SELECT RELEASE_LOCK(?)', ['zunaflux-digest-'.$user]);
    }

    public function add(Uuid $subscription, string $event, int $count, \DateTimeImmutable $now): void
    {
        $this->connection->executeStatement(
            'INSERT INTO notification_pending (subscription_id, event_id, article_count, created_at) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE event_id = VALUES(event_id)',
            [$subscription->toBinary(), Uuid::fromString($event)->toBinary(), $count, $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s')],
        );
    }

    /** @return array{count: int, cursor: int, first: ?\DateTimeImmutable} */
    public function pending(Uuid $user, int $cursor): array
    {
        $row = $this->connection->fetchAssociative('SELECT COALESCE(SUM(p.article_count), 0) AS total, COALESCE(MAX(p.id), 0) AS max_id, MIN(p.created_at) AS first_at FROM notification_pending p JOIN subscription s ON s.id = p.subscription_id WHERE s.user_id = ? AND s.enabled = 1 AND p.id > ?', [$user->toBinary(), $cursor]);
        return ['count' => (int) $row['total'], 'cursor' => (int) $row['max_id'], 'first' => null === $row['first_at'] ? null : new \DateTimeImmutable($row['first_at'], new \DateTimeZone('UTC'))];
    }

    public function latestCursor(): int
    {
        return (int) $this->connection->fetchOne('SELECT COALESCE(MAX(id), 0) FROM notification_pending');
    }

    public function prune(Uuid $user, ?\DateTimeImmutable $now = null): void
    {
        // Retain consumed events for 30 days to deduplicate delayed Messenger retries.
        $cutoff = ($now ?? $this->clock->now())->setTimezone(new \DateTimeZone('UTC'))->modify('-30 days');
        $this->connection->executeStatement('DELETE p FROM notification_pending p JOIN subscription s ON s.id = p.subscription_id WHERE s.user_id = ? AND p.created_at < ? AND NOT EXISTS (SELECT 1 FROM push_subscription d WHERE d.user_id = s.user_id AND d.digest_cursor < p.id)', [$user->toBinary(), $cutoff->format('Y-m-d H:i:s')]);
    }
}
