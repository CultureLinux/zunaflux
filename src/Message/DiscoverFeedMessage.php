<?php

declare(strict_types=1);

namespace App\Message;

final readonly class DiscoverFeedMessage
{
    public function __construct(public string $key, public string $url) {}
}
