<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\User;
use App\Entity\UserPreference;
use PHPUnit\Framework\TestCase;

final class UserPreferenceLocaleTest extends TestCase
{
    public function testLocaleDefaultsToFrenchAndOnlySupportedLanguagesAreAccepted(): void
    {
        $preference = new UserPreference(new User('locale@example.test'));

        self::assertTrue(method_exists($preference, 'getLocale'), 'A locale preference must be available.');
        self::assertSame('fr', $preference->getLocale());

        foreach (['en', 'de', 'fr'] as $locale) {
            $preference->update(['locale' => $locale]);
            self::assertSame($locale, $preference->getLocale());
        }

        $preference->update(['locale' => 'es']);
        self::assertSame('fr', $preference->getLocale());
    }
}
