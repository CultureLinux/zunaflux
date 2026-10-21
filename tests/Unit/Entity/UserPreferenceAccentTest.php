<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\User;
use App\Entity\UserPreference;
use PHPUnit\Framework\TestCase;

final class UserPreferenceAccentTest extends TestCase
{
    public function testAllTenAccentsCanBeSelectedAndUnknownValuesAreRejected(): void
    {
        $preference = new UserPreference(new User('accent@example.test'));
        foreach (['cyan', 'orange', 'emerald', 'blue', 'violet', 'rose', 'red', 'amber', 'lime', 'slate'] as $accent) {
            $preference->update(['accent' => $accent]);
            self::assertSame($accent, $preference->getAccent());
        }
        $preference->update(['accent' => 'unknown']);
        self::assertSame('slate', $preference->getAccent());
    }
}
