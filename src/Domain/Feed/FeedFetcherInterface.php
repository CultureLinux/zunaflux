<?php

declare(strict_types=1);

namespace App\Domain\Feed;

interface FeedFetcherInterface
{
    public function fetch(string $url, ?string $etag = null, ?string $lastModified = null): FetchedFeed;
}
