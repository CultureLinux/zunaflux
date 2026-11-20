<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Feed;

use App\Infrastructure\Feed\DiscoveryFetcher;
use App\Infrastructure\Http\SafeHttpClient;
use App\Infrastructure\Http\UrlSafetyValidator;
use Symfony\Component\HttpClient\MockHttpClient;
use PHPUnit\Framework\TestCase;

final class DiscoveryFetcherTest extends TestCase
{
    public function testRejectsLocalAddressesBeforeConnecting(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new DiscoveryFetcher(new SafeHttpClient(new UrlSafetyValidator(), new MockHttpClient())))->fetch('http://127.0.0.1/');
    }

    public function testRejectsSharedAddressSpaceBeforeConnecting(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new DiscoveryFetcher(new SafeHttpClient(new UrlSafetyValidator(), new MockHttpClient())))->fetch('http://100.64.0.1/');
    }
}
