<?php

declare(strict_types=1);

namespace App\Domain\Feed;

/** Downloads an HTML page (or a feed document) so that feeds can be discovered in it. */
interface PageFetcherInterface
{
    /** @return array{url: string, body: string} the final address (after redirects) and the body */
    public function fetch(string $url): array;
}
