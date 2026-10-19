<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Feed;

use App\Domain\Feed\FeedParser;
use PHPUnit\Framework\TestCase;

final class FeedParserTest extends TestCase
{
    public function testItParsesAnAtomEntryWithAStableIdentity(): void
    {
        $parser = new FeedParser();

        $feed = $parser->parse(<<<'XML'
            <?xml version="1.0"?>
            <feed xmlns="http://www.w3.org/2005/Atom">
              <title>Example</title>
              <entry>
                <id>tag:example.test,2026:one</id>
                <title>First entry</title>
                <updated>2026-10-19T08:00:00Z</updated>
                <link rel="alternate" href="https://example.test/one" />
                <content type="html">&lt;p&gt;Hello&lt;/p&gt;</content>
              </entry>
            </feed>
            XML);

        self::assertSame('Example', $feed->title);
        self::assertCount(1, $feed->items);
        self::assertSame('tag:example.test,2026:one', $feed->items[0]->identity);
        self::assertSame('https://example.test/one', $feed->items[0]->url);
    }

    public function testItRejectsXmlWithADoctype(): void
    {
        $parser = new FeedParser();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('DOCTYPE');

        $parser->parse('<!DOCTYPE rss [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><rss version="2.0"/>');
    }
}
