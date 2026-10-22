<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Application\Administration\RegistrationLimit;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RegistrationLimitTest extends KernelTestCase
{
    public function testEnvironmentOverridesDatabaseAndRejectsUpdates(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        $limit = new RegistrationLimit($connection, '42');
        self::assertSame(42, $limit->effectiveLimit());
        $this->expectException(\DomainException::class);
        $limit->update(5);
    }

    public function testEmptyEnvironmentUsesDatabaseLimit(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        $connection->beginTransaction();
        try {
            $limit = new RegistrationLimit($connection, '');
            $limit->update(25);
            self::assertSame(25, $limit->effectiveLimit());
            $limit->update(null);
            self::assertNull($limit->effectiveLimit());
        } finally {
            $connection->rollBack();
        }
    }
}
