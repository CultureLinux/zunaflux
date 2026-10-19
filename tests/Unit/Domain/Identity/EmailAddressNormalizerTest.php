<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Identity;

use App\Domain\Identity\EmailAddressNormalizer;
use PHPUnit\Framework\TestCase;

final class EmailAddressNormalizerTest extends TestCase
{
    /** A regression in normalization would permit duplicate accounts differing only by case or spacing. */
    public function testItNormalizesWhitespaceAndCaseForUniqueness(): void
    {
        self::assertSame('léa@example.test', EmailAddressNormalizer::normalize('  LéA@EXAMPLE.TEST  '));
    }

    /** A regression here would allow an invalid blank address to enter the identity boundary. */
    public function testItRejectsAnEmptyEmailAddress(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        EmailAddressNormalizer::normalize('   ');
    }
}
