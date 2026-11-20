<?php

declare(strict_types=1);

namespace App\Application\Exception;

final class InvalidFeedAddress extends \InvalidArgumentException implements TranslatableException
{
    public const KEY = 'Saisissez une adresse de flux HTTP ou HTTPS valide, sans identifiants.';

    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct(self::KEY, 0, $previous);
    }

    public function getTranslationKey(): string
    {
        return self::KEY;
    }

    public function getTranslationParameters(): array
    {
        return [];
    }
}
