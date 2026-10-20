<?php

declare(strict_types=1);

namespace App\Message;

final readonly class NotifyFeedMessage
{
    public function __construct(public string $feedId, public string $kind, public int $count = 0) {}
}
