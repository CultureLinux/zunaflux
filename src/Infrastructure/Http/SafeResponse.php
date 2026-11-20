<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

final readonly class SafeResponse
{
    /** @param array<string, string> $headers lower-cased header names, first value only */
    public function __construct(public int $status, public array $headers, public string $body, public string $url)
    {
    }

    public function header(string $name): ?string
    {
        $value = $this->headers[strtolower($name)] ?? null;

        return null === $value || '' === trim($value) ? null : trim($value);
    }
}
