<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

/** An outgoing request that could not deliver a usable response (not an HTTP error status). */
final class HttpFetchFailed extends \RuntimeException
{
    public function __construct(string $message, public readonly HttpFailure $reason, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
