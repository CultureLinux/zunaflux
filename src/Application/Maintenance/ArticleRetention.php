<?php

declare(strict_types=1);

namespace App\Application\Maintenance;

use Doctrine\DBAL\Connection;

final readonly class ArticleRetention
{
    public function __construct(private Connection $connection, private int $retentionMonths)
    {
        if ($retentionMonths < 1) {
            throw new \InvalidArgumentException('La durée de rétention doit être positive.');
        }
    }

    /** @return int number of articles deleted */
    public function purgeBatch(int $batchSize = 100): int
    {
        if ($batchSize < 1 || $batchSize > 1000) {
            throw new \InvalidArgumentException('La taille de lot doit être comprise entre 1 et 1000.');
        }
        $cutoff = (new \DateTimeImmutable())->modify(sprintf('-%d months', $this->retentionMonths));

        return $this->connection->transactional(function (Connection $connection) use ($cutoff, $batchSize): int {
            $ids = $connection->fetchFirstColumn(
                'SELECT a.id FROM article a WHERE COALESCE(a.published_at, a.collected_at) < ? AND NOT EXISTS (SELECT 1 FROM article_state st INNER JOIN subscription sub ON sub.user_id = st.user_id AND sub.feed_id = a.feed_id AND sub.enabled = 1 WHERE st.article_id = a.id AND (st.is_favorite = 1 OR st.is_saved = 1)) ORDER BY COALESCE(a.published_at, a.collected_at) ASC LIMIT '.$batchSize,
                [$cutoff],
                [\Doctrine\DBAL\Types\Types::DATETIME_IMMUTABLE],
            );
            if ([] === $ids) {
                return 0;
            }

            return $connection->executeStatement('DELETE FROM article WHERE id IN (?)', [$ids], [\Doctrine\DBAL\ArrayParameterType::BINARY]);
        });
    }
}
