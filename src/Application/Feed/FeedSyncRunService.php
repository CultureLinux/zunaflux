<?php

declare(strict_types=1);

namespace App\Application\Feed;

use App\Application\Time\Clock;
use App\Application\Time\SystemClock;
use App\Message\CollectFeedMessage;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * A "sync run" is a visible batch of feed collections: one row per run, one pending entry per
 * feed, then one CollectFeedMessage per feed. The handler updates the entries; the UI polls them.
 */
final readonly class FeedSyncRunService
{
    private const ENTRY_COLUMNS = 'f.title, f.canonical_url, e.status, e.new_articles, e.detail';

    public function __construct(
        private Connection $connection,
        private MessageBusInterface $bus,
        private Clock $clock = new SystemClock(),
    ) {
    }

    /**
     * @param iterable<Uuid|string> $feedIds       feed identifiers (Uuid, RFC 4122 string or 16-byte binary)
     * @param bool                  $clearIncident drop the feed's open incident as soon as its collection succeeds (admin retry)
     */
    public function start(iterable $feedIds, bool $clearIncident = false): Uuid
    {
        $run = Uuid::v7();
        $ids = [];
        foreach ($feedIds as $feedId) {
            $ids[(string) $this->uuid($feedId)] = $this->uuid($feedId);
        }
        $this->connection->transactional(function (Connection $connection) use ($run, $ids): void {
            $connection->insert('feed_sync_run', ['id' => $run->toBinary(), 'created_at' => $this->clock->now()->format('Y-m-d H:i:s')]);
            foreach ($ids as $feedId) {
                $connection->insert('feed_sync_run_entry', [
                    'run_id' => $run->toBinary(),
                    'feed_id' => $feedId->toBinary(),
                    'status' => 'pending',
                    'new_articles' => 0,
                ]);
            }
        });
        foreach ($ids as $feedId) {
            $this->bus->dispatch(new CollectFeedMessage((string) $feedId, (string) $run, $clearIncident));
        }

        return $run;
    }

    /**
     * Entries of a run, optionally restricted to what one account may see.
     *
     * @return list<array{title: ?string, canonical_url: string, status: string, new_articles: int|string, detail: ?string}>
     */
    public function entries(Uuid $run, ?Uuid $user = null, ?Uuid $category = null, ?Uuid $feed = null): array
    {
        $sql = 'SELECT '.self::ENTRY_COLUMNS.' FROM feed_sync_run_entry e INNER JOIN feed f ON f.id = e.feed_id';
        $params = [$run->toBinary()];
        $types = [ParameterType::BINARY];
        if (null !== $user) {
            $sql .= ' INNER JOIN subscription s ON s.feed_id = f.id';
        }
        $sql .= ' WHERE e.run_id = ?';
        if (null !== $user) {
            $sql .= ' AND s.user_id = ?';
            $params[] = $user->toBinary();
            $types[] = ParameterType::BINARY;
        }
        if (null !== $category) {
            $sql .= ' AND s.category_id = ?';
            $params[] = $category->toBinary();
            $types[] = ParameterType::BINARY;
        }
        if (null !== $feed) {
            $sql .= ' AND e.feed_id = ?';
            $params[] = $feed->toBinary();
            $types[] = ParameterType::BINARY;
        }

        return $this->connection->fetchAllAssociative($sql.' ORDER BY f.title, f.canonical_url', $params, $types);
    }

    /** @param list<array{status: string}> $entries */
    public static function isComplete(array $entries): bool
    {
        foreach ($entries as $entry) {
            if (in_array($entry['status'], ['pending', 'running'], true)) {
                return false;
            }
        }

        return true;
    }

    private function uuid(Uuid|string $value): Uuid
    {
        if ($value instanceof Uuid) {
            return $value;
        }

        return 16 === strlen($value) ? Uuid::fromBinary($value) : Uuid::fromString($value);
    }
}
