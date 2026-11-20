<?php

declare(strict_types=1);

namespace App\Application\Exception;

final class AlreadySubscribed extends \InvalidArgumentException implements TranslatableException
{
    public const KEY = 'Vous êtes déjà abonné à ce flux.';

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
