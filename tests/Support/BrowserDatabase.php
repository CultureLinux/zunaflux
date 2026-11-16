<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;

final class BrowserDatabase
{
    /** @return array<string, mixed> */
    public static function parameters(string $environment, string $url): array
    {
        if ('browser' !== $environment || '' === $url) {
            throw new \RuntimeException('Browser fixtures require APP_ENV=browser and an explicit test database URL.');
        }
        $parts = parse_url($url);
        parse_str(false === $parts ? '' : ($parts['query'] ?? ''), $query);
        if (array_diff(array_keys($query), ['charset', 'serverVersion'])) {
            throw new \RuntimeException('Unsupported browser database URL parameter.');
        }
        try {
            $parameters = (new DsnParser(['mysql' => 'pdo_mysql']))->parse($url);
        } catch (\Throwable) {
            throw new \RuntimeException('Invalid browser test database URL.');
        }
        if ('pdo_mysql' !== ($parameters['driver'] ?? null)
            || !preg_match('/^zunaflux_(?:browser|performance)_test_[a-z0-9_]+$/D', $parameters['dbname'] ?? '')
        ) {
            throw new \RuntimeException('Only a dedicated zunaflux_browser_test_* or zunaflux_performance_test_* database is allowed.');
        }
        $parameters['serverVersion'] = '10.11.0-MariaDB';

        return $parameters;
    }

    public static function connect(string $environment, string $url): Connection
    {
        $parameters = self::parameters($environment, $url);
        $connection = DriverManager::getConnection($parameters);
        $version = (string) $connection->fetchOne('SELECT VERSION()');
        if (!preg_match('/^10\.11\.[0-9]+.*MariaDB/i', $version)
            || $parameters['dbname'] !== $connection->fetchOne('SELECT DATABASE()')
        ) {
            throw new \RuntimeException('Browser fixtures require the validated MariaDB 10.11 database.');
        }

        return $connection;
    }
}
