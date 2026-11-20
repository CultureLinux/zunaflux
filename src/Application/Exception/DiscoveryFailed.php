<?php

declare(strict_types=1);

namespace App\Application\Exception;

/** A page could not be fetched while looking for feeds; the HTTP status (when known) is the code. */
final class DiscoveryFailed extends \RuntimeException implements TranslatableException
{
    /** @param array<string, scalar> $parameters */
    public function __construct(private readonly string $translationKey, private readonly array $parameters = [], int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($translationKey, $code, $previous);
    }

    public function getTranslationKey(): string
    {
        return $this->translationKey;
    }

    public function getTranslationParameters(): array
    {
        return $this->parameters;
    }
}
