<?php

declare(strict_types=1);

namespace App\Application\Subscription;

final readonly class ImportedSubscription
{
    public function __construct(public string $url, public ?string $categoryName)
    {
    }
}
