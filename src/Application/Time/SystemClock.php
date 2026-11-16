<?php

declare(strict_types=1);

namespace App\Application\Time;

final readonly class SystemClock implements Clock
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable();
    }
}
