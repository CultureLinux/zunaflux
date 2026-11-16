<?php

declare(strict_types=1);

namespace App\Application\Feed;

use App\Application\Notification\NotificationOutbox;
use App\Domain\Feed\FeedFetcherInterface;
use App\Domain\Feed\FeedParser;
use App\Entity\Article;
use App\Entity\Feed;
use App\Message\NotifyFeedMessage;
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
        // Fetch/parse before opening the write transaction; network waits hold no locks.
        $fetched = $this->fetcher->fetch($feed->getCanonicalUrl(), $feed->getEtag(), $feed->getLastModified());
        $parsed = 304 === $fetched->statusCode || null === $fetched->body ? null : $this->parser->parse($fetched->body);

        return $this->entityManager->wrapInTransaction(function () use ($feed, $fetched, $parsed): int {
            $feed->recordFetchValidators($fetched->etag, $fetched->lastModified);
            $new = 0;
            if (null !== $parsed) {
                $feed->refreshMetadata($parsed->title, $parsed->siteUrl);
                foreach ($parsed->items as $item) {
                    if (null === $this->entityManager->getRepository(Article::class)->findOneBy([
                        'feed' => $feed,
                        'identityHash' => hash('sha256', $item->identity, true),
                    ])) {
                        $this->entityManager->persist(new Article($feed, $item));
                        ++$new;
                    }
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
}
