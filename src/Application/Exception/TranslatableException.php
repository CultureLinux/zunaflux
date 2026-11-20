<?php

declare(strict_types=1);

namespace App\Application\Exception;

/**
 * An error whose message is meant for the user. The message is the (French) translation key,
 * so existing `trans($e->getMessage())` call sites keep working; parameters are explicit.
 */
interface TranslatableException extends \Throwable
{
    public function getTranslationKey(): string;

    /** @return array<string, scalar> */
    public function getTranslationParameters(): array;
}
