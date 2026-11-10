<?php

declare(strict_types=1);

namespace App\Application\Mastodon;

final class MastodonApiException extends \RuntimeException
{
    public function __construct(private int $statusCode)
    {
        parent::__construct(401 === $statusCode ? 'Le compte Mastodon doit être reconnecté.' : 'Mastodon a refusé la demande.');
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}
