<?php

declare(strict_types=1);

namespace App\Application\Administration;

use App\Application\Time\Clock;
use App\Application\Time\SystemClock;
use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/** Figures shown on the administration page: activity curves, sync targets and worker status. */
final readonly class AdminStatistics
{
    private const UUID_TEXT = 'LOWER(CONCAT(SUBSTR(HEX(%1$s),1,8),\'-\',SUBSTR(HEX(%1$s),9,4),\'-\',SUBSTR(HEX(%1$s),13,4),\'-\',SUBSTR(HEX(%1$s),17,4),\'-\',SUBSTR(HEX(%1$s),21)))';

    public function __construct(private Connection $connection, private Clock $clock = new SystemClock())
    {
    }

    /**
     * Daily counts over the last 30 days: feeds and articles created, accounts and notifications.
     * With an account, the curves are restricted to what that account follows.
     *
     * @return array<string, list<array{date: string, count: int}>>
     */
    public function activity(?User $user): array
    {
        $start = $this->clock->now()->modify('today')->modify('-29 days');
        $from = $start->format('Y-m-d H:i:s');
        $to = $start->modify('+30 days')->format('Y-m-d H:i:s');
        $series = [
            'flux' => $this->dailyRows('feed', 'created_at', $user, ' INNER JOIN subscription s ON s.feed_id = t.id WHERE s.user_id = ? AND', $from, $to),
            'articles' => $this->dailyRows('article', 'collected_at', $user, ' INNER JOIN subscription s ON s.feed_id = t.feed_id WHERE s.user_id = ? AND', $from, $to),
            'comptes' => $this->dailyRows('app_user', 'created_at', $user, ' WHERE t.id = ? AND', $from, $to),
        ];
        $stats = array_map(fn (array $rows): array => $this->fillDays($start, array_column($rows, 'total', 'day')), $series);
        $notifications = $this->connection->fetchAllAssociative(
            'SELECT day, total FROM notification_daily_count WHERE day >= ? AND day < ?',
            [$start->format('Y-m-d'), $start->modify('+30 days')->format('Y-m-d')],
        );
        $stats['notifications envoyées'] = $this->fillDays($start, array_column($notifications, 'total', 'day'));

        return $stats;
    }

    /**
     * Categories (with their owner) and feeds that an administrator can synchronise.
     *
     * @return array{categories: list<array<string, mixed>>, feeds: list<array<string, mixed>>}
     */
    public function syncTargets(): array
    {
        return [
            'categories' => $this->connection->fetchAllAssociative(
                'SELECT '.sprintf(self::UUID_TEXT, 'c.id').' AS id, c.name, u.email_normalized AS owner, COUNT(DISTINCT s.feed_id) AS feeds'
                .' FROM category c INNER JOIN app_user u ON u.id = c.user_id LEFT JOIN subscription s ON s.category_id = c.id'
                .' GROUP BY c.id, c.name, u.email_normalized ORDER BY c.name, u.email_normalized',
            ),
            'feeds' => $this->connection->fetchAllAssociative(
                'SELECT '.sprintf(self::UUID_TEXT, 'id').' AS id, COALESCE(title, canonical_url) AS label FROM feed ORDER BY label',
            ),
        ];
    }

    /**
     * Messages waiting in the transport (failed ones excluded) and whether a worker reported in recently.
     *
     * @return array{queueCount: int, workerActive: bool}
     */
    public function worker(): array
    {
        $heartbeat = $this->connection->fetchOne('SELECT last_seen_at FROM messenger_worker_heartbeat WHERE name = ?', ['async']);

        return [
            'queueCount' => (int) $this->connection->fetchOne('SELECT COUNT(*) FROM messenger_messages WHERE queue_name <> ?', ['failed']),
            'workerActive' => is_string($heartbeat) && new \DateTimeImmutable($heartbeat) >= $this->clock->now()->modify('-90 seconds'),
        ];
    }

    /**
     * Number of subscriptions per account, in one query.
     *
     * @return array<string, int> keyed by account id (RFC 4122)
     */
    public function subscriptionsPerAccount(): array
    {
        $counts = [];
        foreach ($this->connection->fetchAllAssociative(
            'SELECT '.sprintf(self::UUID_TEXT, 'user_id').' AS id, COUNT(*) AS total FROM subscription GROUP BY user_id',
        ) as $row) {
            $counts[(string) $row['id']] = (int) $row['total'];
        }

        return $counts;
    }

    /** @return list<array{day: string, total: int|string}> */
    private function dailyRows(string $table, string $column, ?User $user, string $accountJoin, string $from, string $to): array
    {
        if (null === $user) {
            return $this->connection->fetchAllAssociative(
                "SELECT DATE(t.{$column}) AS day, COUNT(*) AS total FROM {$table} t WHERE t.{$column} >= ? AND t.{$column} < ? GROUP BY DATE(t.{$column})",
                [$from, $to],
            );
        }

        return $this->connection->fetchAllAssociative(
            "SELECT DATE(t.{$column}) AS day, COUNT(*) AS total FROM {$table} t{$accountJoin} t.{$column} >= ? AND t.{$column} < ? GROUP BY DATE(t.{$column})",
            [$user->getId()->toBinary(), $from, $to],
            [ParameterType::BINARY],
        );
    }

    /**
     * @param array<string, int|string> $counts
     *
     * @return list<array{date: string, count: int}>
     */
    private function fillDays(\DateTimeImmutable $start, array $counts): array
    {
        $days = [];
        for ($i = 0; $i < 30; ++$i) {
            $day = $start->modify('+'.$i.' days')->format('Y-m-d');
            $days[] = ['date' => $day, 'count' => (int) ($counts[$day] ?? 0)];
        }

        return $days;
    }
}
