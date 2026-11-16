<?php

declare(strict_types=1);

namespace App\Message;

use Symfony\Component\Uid\Uuid;

final readonly class NotifyFeedMessage
{
    public string $eventId;

    public function __construct(
        public string $feedId,
        public string $kind,
        public int $count = 0,
        ?string $eventId = null,
    ) {
        $this->eventId = (string) (null === $eventId ? Uuid::v7() : Uuid::fromString($eventId));
    }
}
