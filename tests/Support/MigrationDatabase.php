<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\DBAL\Tools\DsnParser;

final class MigrationDatabase
{
    /** @return array<string, mixed> */
    public static function parameters(string $environment, string $url): array
    {
        if ('test' !== $environment || '' === $url) {
            throw new \RuntimeException('Migration checks require APP_ENV=test and an explicit dedicated test URL.');
        }
        $parts = parse_url($url);
        parse_str(false === $parts ? '' : ($parts['query'] ?? ''), $query);
        if (array_diff(array_keys($query), ['charset', 'serverVersion'])) {
            throw new \RuntimeException('Migration URL only permits charset and serverVersion query parameters.');
        }
        try {
            $parameters = (new DsnParser(['mysql' => 'pdo_mysql']))->parse($url);
        } catch (\Throwable) {
            throw new \RuntimeException('Invalid migration test URL.');
        }
        if ('pdo_mysql' !== ($parameters['driver'] ?? null)
            || !preg_match('/^zunaflux_migrations_test_[a-z0-9_]+$/D', $parameters['dbname'] ?? '')
        ) {
            throw new \RuntimeException('Only a dedicated zunaflux_migrations_test_* MariaDB database is allowed.');
        }
        $parameters['serverVersion'] = '10.11.0-MariaDB';

        return $parameters;
    }
}
