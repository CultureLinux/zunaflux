<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class UserTest extends TestCase
{
    /** A regression would make authentication identities case-sensitive. */
    public function testItUsesTheNormalizedEmailAsUserIdentifier(): void
    {
        $user = new User('  ADMIN@ZUNAFLUX.TEST ');

        self::assertSame('admin@zunaflux.test', $user->getUserIdentifier());
    }

    /** Removing role de-duplication would grant inconsistent access data to Symfony Security. */
    public function testItAddsAnAdministrativeRoleOnlyOnce(): void
    {
        $user = new User('admin@zunaflux.test');
        $user->addRole('ROLE_ADMIN');
        $user->addRole('ROLE_ADMIN');

        self::assertSame(['ROLE_USER', 'ROLE_ADMIN'], $user->getRoles());
    }
}
