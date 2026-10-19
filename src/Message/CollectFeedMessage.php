<?php

declare(strict_types=1);

namespace App\Message;

final readonly class CollectFeedMessage
{
    public function __construct(public string $feedId) {}
}
