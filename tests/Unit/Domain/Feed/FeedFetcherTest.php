<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Feed;

use App\Domain\Feed\FeedFetcher;
use App\Domain\Feed\UrlSafetyValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FeedFetcherTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function locations(): iterable
    {
        yield 'absolute' => ['https://example.test/a/feed.xml', 'https://other.test/x', 'https://other.test/x'];
        yield 'absolute path' => ['https://example.test/a/feed.xml', '/rss', 'https://example.test/rss'];
        yield 'relative' => ['https://example.test/a/feed.xml', 'new.xml', 'https://example.test/a/new.xml'];
        yield 'protocol relative' => ['https://example.test/a/feed.xml', '//cdn.test/f', 'https://cdn.test/f'];
        yield 'keeps the port' => ['http://example.test:80/feed', '/rss', 'http://example.test:80/rss'];
    }

    #[DataProvider('locations')]
    public function testRedirectLocationsAreResolvedAgainstTheCurrentUrl(string $base, string $location, string $expected): void
    {
        $method = new \ReflectionMethod(FeedFetcher::class, 'resolveLocation');

        self::assertSame($expected, $method->invoke(new FeedFetcher(new UrlSafetyValidator()), $base, $location));
    }

    public function testAnUnusableLocationIsRefused(): void
    {
        $method = new \ReflectionMethod(FeedFetcher::class, 'resolveLocation');

        $this->expectException(\RuntimeException::class);
        $method->invoke(new FeedFetcher(new UrlSafetyValidator()), 'https://example.test/feed', 'http://exa mple.test/');
    }
}
