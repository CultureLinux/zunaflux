<?php

declare(strict_types=1);

namespace App\Domain\Feed;

final readonly class ParsedFeedItem
{
    public function __construct(
        public string $identity,
        public string $title,
        public ?string $url,
        public ?string $summary,
        public ?string $content,
        public ?\DateTimeImmutable $publishedAt,
    ) {
    }
}
