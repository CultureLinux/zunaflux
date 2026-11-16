<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure;

use App\Tests\Support\BrowserDatabase;
use PHPUnit\Framework\TestCase;

final class BrowserDatabaseTest extends TestCase
{
    public function testOtherEnvironmentsAndUnsafeTargetsAreRejectedWithoutConnecting(): void
    {
        foreach ([
            ['prod', 'mysql://user:secret@localhost/zunaflux_browser_test_ci'],
            ['test', 'mysql://user:secret@localhost/zunaflux_browser_test_ci'],
            ['browser', ''],
            ['browser', 'mysql://user:secret@localhost/zunaflux'],
            ['browser', 'mysql://user:secret@localhost/zunaflux_test'],
            ['browser', 'mysql://user:secret@localhost/zunaflux_browser_test_'],
            ['browser', 'mysql://user:secret@localhost/zunaflux_browser_test_ci?dbname=zunaflux'],
            ['browser', 'sqlite:///:memory:'],
        ] as [$environment, $url]) {
            try {
                BrowserDatabase::parameters($environment, $url);
                self::fail('Unsafe browser database accepted.');
            } catch (\RuntimeException $error) {
                self::assertStringNotContainsString('secret', $error->getMessage());
            }
        }
    }

    public function testExplicitDedicatedBrowserDatabaseIsAccepted(): void
    {
        $parameters = BrowserDatabase::parameters('browser', 'mysql://user:secret@127.0.0.1/zunaflux_browser_test_ci');
        self::assertSame('zunaflux_browser_test_ci', $parameters['dbname']);
        self::assertSame('pdo_mysql', $parameters['driver']);
    }
}
