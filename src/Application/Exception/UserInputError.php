<?php

declare(strict_types=1);

namespace App\Application\Exception;

/** Invalid input from the user (form field, uploaded file, request payload). */
final class UserInputError extends \InvalidArgumentException implements TranslatableException
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
