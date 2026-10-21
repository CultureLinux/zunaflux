<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Feed;

use App\Domain\Feed\DiscoveryFetcher;
use App\Domain\Feed\UrlSafetyValidator;
use PHPUnit\Framework\TestCase;

final class DiscoveryFetcherTest extends TestCase
{
    public function testRejectsLocalAddressesBeforeConnecting(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new DiscoveryFetcher(new UrlSafetyValidator()))->fetch('http://127.0.0.1/');
    }

    public function testRejectsSharedAddressSpaceBeforeConnecting(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new DiscoveryFetcher(new UrlSafetyValidator()))->fetch('http://100.64.0.1/');
    }
}
