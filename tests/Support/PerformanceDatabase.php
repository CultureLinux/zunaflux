<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Tools\DsnParser;

final class PerformanceDatabase
{
    /** @return array<string, mixed> */
    public static function parameters(string $environment, string $url): array
    {
        if ('test' !== $environment || '' === $url) {
            throw new \RuntimeException('Performance tooling requires APP_ENV=test and PERFORMANCE_TEST_DATABASE_URL.');
        }
        $parts = parse_url($url);
        parse_str(false === $parts ? '' : ($parts['query'] ?? ''), $query);
        if (array_diff(array_keys($query), ['charset', 'serverVersion'])) {
            throw new \RuntimeException('Unsupported performance database URL parameter.');
        }
        try {
            $parameters = (new DsnParser(['mysql' => 'pdo_mysql']))->parse($url);
        } catch (\Throwable) {
            throw new \RuntimeException('Invalid performance database URL.');
        }
        if ('pdo_mysql' !== ($parameters['driver'] ?? null)
            || !preg_match('/^zunaflux_performance_test_[a-z0-9_]+$/D', $parameters['dbname'] ?? '')
        ) {
            throw new \RuntimeException('Only a dedicated zunaflux_performance_test_* database is allowed.');
        }
        $parameters['serverVersion'] = '10.11.0-MariaDB';

        return $parameters;
    }

    public static function guard(Connection $connection): void
    {
        if (!preg_match('/^10\.11\.[0-9]+.*MariaDB/i', (string) $connection->fetchOne('SELECT VERSION()'))) {
            throw new \RuntimeException('Performance tooling requires MariaDB 10.11.');
        }
        if (($connection->getParams()['dbname'] ?? null) !== $connection->fetchOne('SELECT DATABASE()')) {
            throw new \RuntimeException('Performance database mismatch.');
        }
    }
}
