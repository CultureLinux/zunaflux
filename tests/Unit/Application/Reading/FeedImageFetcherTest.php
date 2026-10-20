<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Reading;

use App\Domain\Feed\UrlSafetyValidator;
use App\Infrastructure\Reading\FeedImageFetcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FeedImageFetcherTest extends TestCase
{
    #[DataProvider('blockedUrls')]
    public function testRejectsUnsafeAddressesBeforeFetching(string $url): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new FeedImageFetcher(new UrlSafetyValidator()))->fetch($url);
    }

    /** @return iterable<string, array{string}> */
    public static function blockedUrls(): iterable
    {
        yield 'loopback' => ['http://127.0.0.1/image.png'];
        yield 'private network' => ['http://192.168.1.1/image.png'];
        yield 'shared address space' => ['http://100.64.0.1/image.png'];
        yield 'mapped loopback' => ['http://[::ffff:127.0.0.1]/image.png'];
        yield 'metadata' => ['http://169.254.169.254/image.png'];
        yield 'credentials' => ['http://user:password@example.test/image.png'];
        yield 'unsupported protocol' => ['file:///etc/passwd'];
        yield 'nonstandard port' => ['https://example.test:8443/image.png'];
    }
}
