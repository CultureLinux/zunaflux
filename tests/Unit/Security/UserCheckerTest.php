<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Entity\User;
use App\Security\UserChecker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class UserCheckerTest extends TestCase
{
    public function testAnActiveAccountMayAuthenticate(): void
    {
        $checker = new UserChecker();
        $checker->checkPreAuth(new User('active@example.test'));
        $checker->checkPostAuth(new User('active@example.test'));

        self::addToAssertionCount(1);
    }

    public function testADisabledAccountIsRefusedBeforeAuthentication(): void
    {
        $user = new User('disabled@example.test');
        $user->disable();

        $this->expectException(CustomUserMessageAccountStatusException::class);
        $this->expectExceptionMessage('Ce compte est désactivé.');
        (new UserChecker())->checkPreAuth($user);
    }

    public function testForeignUserTypesAreNotJudged(): void
    {
        (new UserChecker())->checkPreAuth(new InMemoryUser('someone', null));

        self::addToAssertionCount(1);
    }
}
