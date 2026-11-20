<?php

declare(strict_types=1);

namespace App\Application\Exception;

final class ArticleInaccessible extends \DomainException implements TranslatableException
{
    public const KEY = 'Article inaccessible.';

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
