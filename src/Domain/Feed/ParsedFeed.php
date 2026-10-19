<?php

declare(strict_types=1);

namespace App\Domain\Feed;

final readonly class ParsedFeed
{
    /** @param list<ParsedFeedItem> $items */
    public function __construct(public ?string $title, public ?string $siteUrl, public array $items)
    {
    }
}
