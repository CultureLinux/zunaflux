<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure;

use App\Tests\Support\MigrationDatabase;
use PHPUnit\Framework\TestCase;

final class MigrationDatabaseTest extends TestCase
{
    public function testUnsafeTargetsAreRejectedBeforeAnyConnection(): void
    {
        foreach ([
            ['prod', 'mysql://user:secret@localhost/zunaflux_migrations_test_empty'],
            ['test', ''],
            ['test', 'mysql://user:secret@localhost/zunaflux'],
            ['test', 'mysql://user:secret@localhost/zunaflux_test'],
            ['test', 'mysql://user:secret@localhost/zunaflux_migrations_test_'],
            ['test', 'mysql://user:secret@localhost/zunaflux_migrations_test_empty-other'],
            ['test', 'mysql://user:secret@localhost/zunaflux_migrations_test_empty?dbname=zunaflux'],
            ['test', 'postgresql://user:secret@localhost/zunaflux_migrations_test_empty'],
            ['test', 'sqlite:///:memory:'],
        ] as [$environment, $url]) {
            try {
                MigrationDatabase::parameters($environment, $url);
                self::fail('Unsafe migration target accepted.');
            } catch (\RuntimeException $error) {
                self::assertStringNotContainsString('secret', $error->getMessage());
            }
        }
    }

    public function testAnExplicitDedicatedTargetIsAccepted(): void
    {
        $parameters = MigrationDatabase::parameters('test', 'mysql://user:secret@127.0.0.1:3306/zunaflux_migrations_test_empty');
        self::assertSame('zunaflux_migrations_test_empty', $parameters['dbname']);
        self::assertSame('pdo_mysql', $parameters['driver']);
    }
}
