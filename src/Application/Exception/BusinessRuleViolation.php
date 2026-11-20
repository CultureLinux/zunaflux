<?php

declare(strict_types=1);

namespace App\Application\Exception;

/** A request that is well-formed but refused by a business rule. */
final class BusinessRuleViolation extends \DomainException implements TranslatableException
{
    /** @param array<string, scalar> $parameters */
    public function __construct(private readonly string $translationKey, private readonly array $parameters = [], ?\Throwable $previous = null)
    {
        parent::__construct($translationKey, 0, $previous);
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
