<?php

declare(strict_types=1);

namespace App\Application\Exception;

final class CategoryAlreadyExists extends \InvalidArgumentException implements TranslatableException
{
    public const KEY = 'Cette catégorie existe déjà.';

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
