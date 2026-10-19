<?php

declare(strict_types=1);

namespace App\Domain\Feed;

final readonly class FetchedFeed
{
    public function __construct(public int $statusCode, public ?string $body, public ?string $etag, public ?string $lastModified, public string $url)
    {
    }
}
