<?php

declare(strict_types=1);

namespace App\Application\Reading;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Uid\Uuid;

/** Read/unread/favorite counters of an account, computed in SQL over its enabled subscriptions. */
final readonly class ReadingCounters
{
    private const TOTALS = 'COUNT(a.id) AS total,'
        .' COALESCE(SUM(CASE WHEN st.is_read = 1 THEN 1 ELSE 0 END), 0) AS read_count,'
        .' COALESCE(SUM(CASE WHEN st.is_read = 1 THEN 0 ELSE 1 END), 0) AS unread_count';

    public function __construct(private Connection $connection)
    {
    }

    /**
     * Counters per subscription, for the reading sidebar.
     *
     * @return array<string, array{unread: int, read: int, favorite: int, saved: int}>
     */
    public function perSubscription(User $user): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT s.id, COUNT(a.id) AS total,'
            .' COALESCE(SUM(CASE WHEN a.id IS NOT NULL AND COALESCE(st.is_read, 0) = 0 THEN 1 ELSE 0 END), 0) AS unread,'
            .' COALESCE(SUM(CASE WHEN COALESCE(st.is_favorite, 0) = 1 THEN 1 ELSE 0 END), 0) AS favorite,'
            .' COALESCE(SUM(CASE WHEN COALESCE(st.is_saved, 0) = 1 THEN 1 ELSE 0 END), 0) AS saved'
            .' FROM subscription s LEFT JOIN article a ON a.feed_id = s.feed_id'
            .' LEFT JOIN article_state st ON st.article_id = a.id AND st.user_id = s.user_id'
            .' WHERE s.user_id = ? AND s.enabled = 1 GROUP BY s.id',
            [$user->getId()->toBinary()],
            [ParameterType::BINARY],
        );
        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) Uuid::fromBinary($row['id'])] = [
                'unread' => (int) $row['unread'],
                'read' => (int) $row['total'] - (int) $row['unread'],
                'favorite' => (int) $row['favorite'],
                'saved' => (int) $row['saved'],
            ];
        }

        return $counts;
    }

    /**
     * Overall, per-category and per-feed totals (the API counters endpoint).
     *
     * @return array{all: array<string, mixed>|false, categories: list<array<string, mixed>>, feeds: list<array<string, mixed>>}
     */
    public function summary(User $user): array
    {
        $parameters = [$user->getId()->toBinary()];
        $types = [ParameterType::BINARY];
        $articles = ' INNER JOIN article a ON a.feed_id = s.feed_id'
            .' LEFT JOIN article_state st ON st.article_id = a.id AND st.user_id = s.user_id'
            .' WHERE s.user_id = ? AND s.enabled = 1';
        $result = [
            'all' => $this->connection->fetchAssociative('SELECT '.self::TOTALS.' FROM subscription s'.$articles, $parameters, $types),
            'categories' => $this->connection->fetchAllAssociative(
                'SELECT c.id AS id, c.name AS name, '.self::TOTALS.' FROM subscription s LEFT JOIN category c ON c.id = s.category_id'
                .$articles.' GROUP BY c.id, c.name',
                $parameters,
                $types,
            ),
            'feeds' => $this->connection->fetchAllAssociative(
                'SELECT f.id AS id, f.title AS title, f.canonical_url AS url, '.self::TOTALS.' FROM subscription s INNER JOIN feed f ON f.id = s.feed_id'
                .$articles.' GROUP BY f.id, f.title, f.canonical_url',
                $parameters,
                $types,
            ),
        ];
        foreach (['categories', 'feeds'] as $group) {
            foreach ($result[$group] as &$row) {
                if (null !== $row['id']) {
                    $row['id'] = (string) Uuid::fromBinary($row['id']);
                }
            }
            unset($row);
        }

        return $result;
    }
}
