<?php

declare(strict_types=1);

namespace App\Application\Feed;

use App\Domain\Feed\FeedFetcher;
use App\Domain\Feed\FeedParser;
use App\Entity\Article;
use App\Entity\Feed;
use Doctrine\ORM\EntityManagerInterface;

final readonly class CollectFeed
{
    public function __construct(private EntityManagerInterface $entityManager, private FeedFetcher $fetcher, private FeedParser $parser) {}
    public function collect(Feed $feed): int
    {
        $fetched = $this->fetcher->fetch($feed->getCanonicalUrl(), $feed->getEtag(), $feed->getLastModified());
        $feed->recordFetchValidators($fetched->etag, $fetched->lastModified);
        if (304 === $fetched->statusCode || null === $fetched->body) { $this->entityManager->flush(); return 0; }
        $parsed = $this->parser->parse($fetched->body); $feed->refreshMetadata($parsed->title, $parsed->siteUrl); $new = 0;
        foreach ($parsed->items as $item) { if (null === $this->entityManager->getRepository(Article::class)->findOneBy(['feed' => $feed, 'identityHash' => hash('sha256', $item->identity, true)])) { $this->entityManager->persist(new Article($feed, $item)); ++$new; } }
        $this->entityManager->flush(); return $new;
    }
}
