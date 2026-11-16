<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure;

use App\Tests\Support\PerformanceDatabase;
use PHPUnit\Framework\TestCase;

final class PerformanceDatabaseTest extends TestCase
{
    public function testUnsafeTargetsAreRejectedWithoutConnecting(): void
    {
        foreach ([
            ['prod', 'mysql://user:secret@localhost/zunaflux_performance_test_local'],
            ['test', ''],
            ['test', 'mysql://user:secret@localhost/zunaflux'],
            ['test', 'mysql://user:secret@localhost/zunaflux_browser_test_local'],
            ['test', 'mysql://user:secret@localhost/zunaflux_performance_test_'],
            ['test', 'mysql://user:secret@localhost/zunaflux_performance_test_local?dbname=zunaflux'],
            ['test', 'sqlite:///:memory:'],
        ] as [$environment, $url]) {
            try {
                PerformanceDatabase::parameters($environment, $url);
                self::fail('Unsafe performance database accepted.');
            } catch (\RuntimeException $error) {
                self::assertStringNotContainsString('secret', $error->getMessage());
            }
        }
    }

    public function testDedicatedDatabaseIsAccepted(): void
    {
        $parameters = PerformanceDatabase::parameters('test', 'mysql://user:secret@localhost/zunaflux_performance_test_local?charset=utf8mb4');
        self::assertSame('pdo_mysql', $parameters['driver']);
        self::assertSame('zunaflux_performance_test_local', $parameters['dbname']);
    }
}
