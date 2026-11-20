<?php

declare(strict_types=1);

namespace App\Application\Maintenance;

use App\Application\Time\Clock;
use App\Application\Time\SystemClock;
use Doctrine\DBAL\Connection;

final readonly class ArticleRetention
{
    public function __construct(
        private Connection $connection,
        private int $retentionMonths,
        private Clock $clock = new SystemClock(),
    ) {
        if ($retentionMonths < 1) {
            throw new \InvalidArgumentException('La durée de rétention doit être positive.');
        }
    }

    /**
     * Purges batches until nothing is left or the time budget is spent.
     * At least one batch always runs; the budget is checked between batches.
     *
     * @return int number of articles deleted
     */
    public function purge(int $batchSize = 100, int $maxSeconds = 300): int
    {
        $deadline = $this->clock->now()->getTimestamp() + max(0, $maxSeconds);
        $total = 0;
        do {
            $deleted = $this->purgeBatch($batchSize);
            $total += $deleted;
        } while (0 !== $deleted && $this->clock->now()->getTimestamp() < $deadline);

        return $total;
    }

    /** @return int number of articles deleted */
    public function purgeBatch(int $batchSize = 100): int
    {
        if ($batchSize < 1 || $batchSize > 1000) {
            throw new \InvalidArgumentException('La taille de lot doit être comprise entre 1 et 1000.');
        }
        // collected_at only: an item still present in its feed must stay older than the
        // retention window before deletion, otherwise the next collection re-inserts it as new.
        $cutoff = $this->clock->now()->modify(sprintf('-%d months', $this->retentionMonths));
        $protected = 'EXISTS (SELECT 1 FROM article_state st INNER JOIN subscription sub ON sub.user_id = st.user_id AND sub.feed_id = %1$s.feed_id AND sub.enabled = 1 WHERE st.article_id = %1$s.id AND (st.is_favorite = 1 OR st.is_saved = 1))';

        return $this->connection->transactional(function (Connection $connection) use ($cutoff, $batchSize, $protected): int {
            $ids = $connection->fetchFirstColumn(
                'SELECT a.id FROM article a WHERE a.collected_at < ? AND NOT '.sprintf($protected, 'a').' ORDER BY a.collected_at ASC, a.id ASC LIMIT '.$batchSize,
                [$cutoff],
                [\Doctrine\DBAL\Types\Types::DATETIME_IMMUTABLE],
            );
            if ([] === $ids) {
                return 0;
            }

            // Protection is re-checked in the DELETE: a favorite added since the SELECT survives.
            return $connection->executeStatement(
                'DELETE FROM article WHERE id IN (?) AND NOT '.sprintf($protected, 'article'),
                [$ids],
                [\Doctrine\DBAL\ArrayParameterType::BINARY],
            );
        });
    }
}
