<?php

declare(strict_types=1);

namespace App\Domain\Identity;

final class EmailAddressNormalizer
{
    public static function normalize(string $email): string
    {
        $normalized = mb_strtolower(trim($email));

        if ($normalized === '') {
            throw new \InvalidArgumentException('An email address cannot be empty.');
        }

        return $normalized;
    }
}
