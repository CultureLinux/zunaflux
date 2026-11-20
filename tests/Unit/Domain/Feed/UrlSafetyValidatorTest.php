<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Feed;

use App\Domain\Feed\UrlSafetyValidator;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /** @return iterable<string, array{string}> */
    public static function extraReservedAddresses(): iterable
    {
        yield 'CGNAT' => ['http://100.64.0.1/feed'];
        yield 'CGNAT upper bound' => ['http://100.127.255.254/feed'];
        yield 'benchmarking' => ['http://198.19.0.1/feed'];
        yield 'NAT64' => ['http://[64:ff9b::7f00:1]/feed'];
        yield '6to4' => ['http://[2002:7f00:1::1]/feed'];
        yield 'Teredo' => ['http://[2001:0:4136:e378::1]/feed'];
        yield 'IPv4-mapped loopback' => ['http://[::ffff:7f00:1]/feed'];
    }

    #[DataProvider('extraReservedAddresses')]
    public function testItRejectsReservedRangesMissedByThePhpFilter(string $url): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('private or reserved network');

        (new UrlSafetyValidator())->assertAllowed($url);
    }

    public function testItAcceptsPublicAddressesNextToBlockedRanges(): void
    {
        $validator = new UrlSafetyValidator();

        self::assertSame(['100.128.0.1'], $validator->assertAllowed('http://100.128.0.1/feed'));
        self::assertSame(['198.20.0.1'], $validator->assertAllowed('http://198.20.0.1/feed'));
        self::assertSame(['93.184.216.34'], $validator->assertAllowed('https://93.184.216.34/feed'));
    }
}
