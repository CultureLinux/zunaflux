<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Application\Time\Clock;

final class FrozenClock implements Clock
{
    public function __construct(private \DateTimeImmutable $instant)
    {
    }

    public function now(): \DateTimeImmutable
    {
        return $this->instant;
    }

    public function set(\DateTimeImmutable $instant): void
    {
        $this->instant = $instant;
    }
}
