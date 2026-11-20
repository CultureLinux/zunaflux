<?php

declare(strict_types=1);

namespace App\Application\Feed;

use App\Application\Notification\NotificationOutbox;
use App\Domain\Feed\FeedFetcherInterface;
use App\Domain\Feed\FeedParser;
use App\Entity\Article;
use App\Entity\Feed;
use App\Message\NotifyFeedMessage;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;

final readonly class CollectFeed
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private FeedFetcherInterface $fetcher,
        private FeedParser $parser,
        private NotificationOutbox $outbox,
    ) {
    }

    public function collect(Feed $feed): int
    {
        $connection = $this->entityManager->getConnection();
        $lockName = 'zunaflux-feed-'.$feed->getId()->toRfc4122();
        // One collector per feed: parallel cron, user and admin refreshes must not race
        // on uniq_article_feed_identity_hash.
        if (1 !== (int) $connection->fetchOne('SELECT GET_LOCK(?, 0)', [$lockName])) {
            throw new FeedCollectionInProgress('Feed collection is already running.');
        }

        try {
            return $this->collectLocked($feed);
        } finally {
            try {
                $connection->fetchOne('SELECT RELEASE_LOCK(?)', [$lockName]);
            } catch (\Throwable) {
                // The lock is bound to the session and disappears with the connection.
            }
        }
    }

    private function collectLocked(Feed $feed): int
    {
        // Fetch/parse before opening the write transaction; network waits hold no locks.
        $fetched = $this->fetcher->fetch($feed->getCanonicalUrl(), $feed->getEtag(), $feed->getLastModified());
        $parsed = 304 === $fetched->statusCode || null === $fetched->body ? null : $this->parser->parse($fetched->body);

        return $this->entityManager->wrapInTransaction(function () use ($feed, $fetched, $parsed): int {
            // A 304 may omit the validators: keep the ones that produced it.
            $notModified = 304 === $fetched->statusCode;
            $feed->recordFetchValidators(
                $fetched->etag ?? ($notModified ? $feed->getEtag() : null),
                $fetched->lastModified ?? ($notModified ? $feed->getLastModified() : null),
            );
            $new = 0;
            if (null !== $parsed) {
                $feed->refreshMetadata($parsed->title, $parsed->siteUrl);
                $items = [];
                foreach ($parsed->items as $item) {
                    // A feed may repeat an identity: keep the first occurrence only.
                    $items[hash('sha256', $item->identity, true)] ??= $item;
                }
                $existing = $this->existingHashes($feed, array_keys($items));
                foreach ($items as $hash => $item) {
                    if (isset($existing[$hash])) {
                        continue;
                    }
                    $this->entityManager->persist(new Article($feed, $item));
                    ++$new;
                }
            }
            $feed->recordSuccess();
            if ($new > 0) {
                $this->outbox->add(new NotifyFeedMessage((string) $feed->getId(), 'articles', $new));
            }

            // wrapInTransaction flushes the ORM before committing the same default
            // connection as NotificationOutbox. Failure rolls back both writes.
            return $new;
        });
    }

    /**
     * @param list<string> $hashes binary SHA-256 identities
     *
     * @return array<string, true>
     */
    private function existingHashes(Feed $feed, array $hashes): array
    {
        $existing = [];
        foreach (array_chunk($hashes, 500) as $chunk) {
            $rows = $this->entityManager->createQueryBuilder()
                ->select('a.identityHash AS hash')
                ->from(Article::class, 'a')
                ->where('a.feed = :feed AND a.identityHash IN (:hashes)')
                ->setParameter('feed', $feed->getId(), 'uuid')
                ->setParameter('hashes', $chunk, ArrayParameterType::BINARY)
                ->getQuery()
                ->getScalarResult();
            foreach ($rows as $row) {
                $existing[(string) $row['hash']] = true;
            }
        }

        return $existing;
    }
}
