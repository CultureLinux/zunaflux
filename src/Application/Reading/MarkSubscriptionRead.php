<?php

declare(strict_types=1);

namespace App\Application\Reading;

use App\Entity\Subscription;
use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Set-based "mark as read": a handful of statements per batch of articles instead of
 * several queries and a flush per article. It writes through DBAL and bypasses the
 * UnitOfWork: do not reuse ArticleState entities loaded before the call.
 */
final readonly class MarkSubscriptionRead
{
    private const BATCH_SIZE = 1000;

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /** @return int number of articles marked as read */
    public function mark(Subscription $subscription): int
    {
        return $this->markMatching(
            $subscription->getUser(),
            'a.feed_id = :feed',
            ['feed' => $subscription->getFeed()->getId()->toBinary()],
            ['feed' => ParameterType::BINARY],
        );
    }

    /** Marks every unread article of the user's enabled subscriptions as read. */
    public function markAll(User $user): int
    {
        return $this->markMatching($user, '1 = 1', [], []);
    }

    /** Marks every article of the user's enabled subscriptions dated before the given instant. */
    public function markBefore(User $user, \DateTimeImmutable $before): int
    {
        return $this->markMatching(
            $user,
            'COALESCE(a.published_at, a.collected_at) < :before',
            ['before' => $before],
            ['before' => Types::DATETIME_IMMUTABLE],
        );
    }

    /**
     * @param array<string, mixed>  $params
     * @param array<string, ParameterType|string> $types
     */
    private function markMatching(User $user, string $where, array $params, array $types): int
    {
        $connection = $this->entityManager->getConnection();
        $userId = $user->getId()->toBinary();
        $cursor = str_repeat("\0", 16);
        $total = 0;

        do {
            $rows = $connection->fetchAllAssociative(
                'SELECT a.id, a.feed_id, a.identity_hash, COALESCE(c.name, \'\') AS category
                 FROM article a
                 INNER JOIN subscription sub ON sub.feed_id = a.feed_id AND sub.user_id = :user AND sub.enabled = 1
                 LEFT JOIN category c ON c.id = sub.category_id
                 LEFT JOIN article_state st ON st.article_id = a.id AND st.user_id = :user
                 WHERE '.$where.' AND (st.id IS NULL OR st.is_read = 0) AND a.id > :cursor
                 ORDER BY a.id ASC LIMIT '.self::BATCH_SIZE,
                $params + ['user' => $userId, 'cursor' => $cursor],
                $types + ['user' => ParameterType::BINARY, 'cursor' => ParameterType::BINARY],
            );
            if ([] === $rows) {
                break;
            }
            $connection->transactional(function (Connection $connection) use ($userId, $rows): void {
                $this->markBatch($connection, $userId, $rows);
            });
            $total += count($rows);
            $cursor = $rows[array_key_last($rows)]['id'];
        } while (self::BATCH_SIZE === count($rows));

        return $total;
    }

    /** @param list<array{id: string, feed_id: string, identity_hash: string, category: string}> $rows */
    private function markBatch(Connection $connection, string $userId, array $rows): void
    {
        $now = new \DateTimeImmutable();
        $timestamp = $now->format('Y-m-d H:i:s');

        $values = [];
        $params = [];
        $types = [];
        foreach ($rows as $row) {
            $values[] = '(?, ?, ?, 1, ?, 0, 0, ?)';
            array_push($params, Uuid::v7()->toBinary(), $userId, $row['id'], $timestamp, $timestamp);
            array_push($types, ParameterType::BINARY, ParameterType::BINARY, ParameterType::BINARY, ParameterType::STRING, ParameterType::STRING);
        }
        $connection->executeStatement(
            'INSERT INTO article_state (id, user_id, article_id, is_read, read_at, is_favorite, is_saved, updated_at) VALUES '.implode(', ', $values)
            .' ON DUPLICATE KEY UPDATE is_read = 1, read_at = VALUES(read_at), updated_at = VALUES(updated_at)',
            $params,
            $types,
        );

        $this->recordFirstReads($connection, $userId, $rows, $now);
    }

    /** @param list<array{id: string, feed_id: string, identity_hash: string, category: string}> $rows */
    private function recordFirstReads(Connection $connection, string $userId, array $rows, \DateTimeImmutable $now): void
    {
        $pairs = [];
        $params = [$userId];
        $types = [ParameterType::BINARY];
        foreach ($rows as $row) {
            $pairs[] = '(?, ?)';
            array_push($params, $row['feed_id'], $row['identity_hash']);
            array_push($types, ParameterType::BINARY, ParameterType::BINARY);
        }
        $known = [];
        foreach ($connection->fetchAllAssociative(
            'SELECT feed_id, article_identity_hash FROM first_read_event WHERE user_id = ? AND (feed_id, article_identity_hash) IN ('.implode(', ', $pairs).')',
            $params,
            $types,
        ) as $existing) {
            $known[$existing['feed_id'].$existing['article_identity_hash']] = true;
        }

        $values = [];
        $params = [];
        $types = [];
        $perCategory = [];
        foreach ($rows as $row) {
            if (isset($known[$row['feed_id'].$row['identity_hash']])) {
                continue;
            }
            $values[] = '(?, ?, ?, ?, ?)';
            array_push($params, Uuid::v7()->toBinary(), $userId, $row['feed_id'], $row['identity_hash'], $now->format('Y-m-d H:i:s'));
            array_push($types, ParameterType::BINARY, ParameterType::BINARY, ParameterType::BINARY, ParameterType::BINARY, ParameterType::STRING);
            $category = mb_substr($row['category'], 0, 100);
            $perCategory[$category] = ($perCategory[$category] ?? 0) + 1;
        }
        if ([] === $values) {
            return;
        }
        $connection->executeStatement(
            'INSERT IGNORE INTO first_read_event (id, user_id, feed_id, article_identity_hash, first_read_at) VALUES '.implode(', ', $values),
            $params,
            $types,
        );

        foreach ($perCategory as $category => $count) {
            $connection->executeStatement(
                'INSERT INTO reading_daily_stat (id, user_id, stat_date, category_snapshot, first_reads) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE first_reads = first_reads + VALUES(first_reads)',
                [Uuid::v7()->toBinary(), $userId, $now->format('Y-m-d'), (string) $category, $count],
                [ParameterType::BINARY, ParameterType::BINARY, ParameterType::STRING, ParameterType::STRING, ParameterType::INTEGER],
            );
        }
    }
}
