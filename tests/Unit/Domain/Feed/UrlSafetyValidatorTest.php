<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Feed;

use App\Domain\Feed\UrlSafetyValidator;
use PHPUnit\Framework\TestCase;

final class UrlSafetyValidatorTest extends TestCase
{
    public function testItRejectsPrivateNetworkTargetsBeforeAnyRequest(): void
    {
        $validator = new UrlSafetyValidator();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('private or reserved network');

        $validator->assertAllowed('http://127.0.0.1/feed.xml');
    }

    public function testItRejectsUrlsWithCredentials(): void
    {
        $validator = new UrlSafetyValidator();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('credentials');

        $validator->assertAllowed('https://reader:secret@example.com/feed.xml');
    }
}
