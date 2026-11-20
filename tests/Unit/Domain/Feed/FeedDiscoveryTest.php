<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Feed;

use App\Domain\Feed\FeedDiscovery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FeedDiscoveryTest extends TestCase
{
    public function testDirectRssAndAtomDocumentsAreRecognized(): void
    {
        $discovery = new FeedDiscovery();
        self::assertSame([['url' => 'https://example.com/feed', 'title' => 'Flux RSS']], $discovery->find('<rss version="2.0"><channel><title>Test</title></channel></rss>', 'https://example.com/feed'));
        self::assertSame([['url' => 'https://example.com/atom', 'title' => 'Flux Atom']], $discovery->find('<feed xmlns="http://www.w3.org/2005/Atom"><title>Test</title></feed>', 'https://example.com/atom'));
    }

    public function testFindsRssAndAtomLinksAndResolvesRelativeUrls(): void
    {
        $html = '<html><head><link rel="alternate" type="application/rss+xml" title="Actualités" href="../rss.xml"><link rel="alternate" type="application/atom+xml" href="/atom"><link rel="stylesheet" href="/style.css"></head></html>';
        self::assertSame([
            ['url' => 'https://example.com/rss.xml', 'title' => 'Actualités'],
            ['url' => 'https://example.com/atom', 'title' => 'Flux Atom'],
        ], (new FeedDiscovery())->find($html, 'https://example.com/news/index'));
    }

    public function testRejectsUnsafeSchemesAndCredentialsAndDeduplicates(): void
    {
        $html = '<link rel="alternate" type="application/rss+xml" href="javascript:alert(1)"><link rel="alternate" type="application/rss+xml" href="http://user:pass@example.com/rss"><link rel="alternate" type="application/rss+xml" href="/rss"><link rel="alternate" type="application/rss+xml" href="/rss">';
        self::assertSame([['url' => 'https://example.com/rss', 'title' => 'Flux RSS']], (new FeedDiscovery())->find($html, 'https://example.com/'));
    }

    public function testResolvesRelativeRedirectsAndQueryOnlyReferences(): void
    {
        $discovery = new FeedDiscovery();
        self::assertSame('https://example.com/rss', $discovery->resolve('../rss', 'https://example.com/news/index'));
        self::assertSame('https://example.com/news/index?feed=1', $discovery->resolve('?feed=1', 'https://example.com/news/index'));
        self::assertNull($discovery->resolve('http://user@example.com/rss', 'https://example.com/'));
    }

    /** @return iterable<string, array{string, string, ?string}> */
    public static function redirectLocations(): iterable
    {
        yield 'absolute' => ['https://example.test/a/feed.xml', 'https://other.test/x', 'https://other.test/x'];
        yield 'absolute path' => ['https://example.test/a/feed.xml', '/rss', 'https://example.test/rss'];
        yield 'relative' => ['https://example.test/a/feed.xml', 'new.xml', 'https://example.test/a/new.xml'];
        yield 'dot segments' => ['https://example.test/a/b/feed.xml', '../new.xml', 'https://example.test/a/new.xml'];
        yield 'protocol relative' => ['https://example.test/a/feed.xml', '//cdn.test/f', 'https://cdn.test/f'];
        yield 'keeps the port' => ['http://example.test:80/feed', '/rss', 'http://example.test:80/rss'];
        yield 'unusable' => ['https://example.test/feed', 'http://exa mple.test/', null];
    }

    #[DataProvider('redirectLocations')]
    public function testRedirectLocationsAreResolvedAgainstTheCurrentUrl(string $base, string $location, ?string $expected): void
    {
        self::assertSame($expected, (new FeedDiscovery())->resolve($location, $base));
    }
}
