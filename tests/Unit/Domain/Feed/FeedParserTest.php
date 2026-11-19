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

    public function testItReadsTheChannelOfAnRdfFeed(): void
    {
        $feed = (new FeedParser())->parse(<<<'XML'
            <?xml version="1.0"?>
            <rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#" xmlns="http://purl.org/rss/1.0/">
              <channel rdf:about="https://example.test/">
                <title>RDF channel</title>
                <link>https://example.test/</link>
              </channel>
              <item rdf:about="https://example.test/one">
                <title>One</title>
                <link>https://example.test/one</link>
              </item>
            </rdf:RDF>
            XML);

        self::assertSame('RDF channel', $feed->title);
        self::assertSame('https://example.test/', $feed->siteUrl);
        self::assertCount(1, $feed->items);
        self::assertSame('https://example.test/one', $feed->items[0]->url);
    }

    public function testAnAtomLinkNeverReplacesTheRssLink(): void
    {
        $feed = (new FeedParser())->parse(<<<'XML'
            <?xml version="1.0"?>
            <rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">
              <channel>
                <title>Mixed</title>
                <atom:link rel="self" href="https://example.test/feed.xml"/>
                <link>https://example.test/</link>
                <item>
                  <atom:link rel="self" href="https://example.test/item.xml"/>
                  <title>Item</title>
                  <link>https://example.test/item</link>
                </item>
              </channel>
            </rss>
            XML);

        self::assertSame('https://example.test/', $feed->siteUrl);
        self::assertSame('https://example.test/item', $feed->items[0]->url);
    }

    public function testItAcceptsAnEntityFreeDoctype(): void
    {
        $feed = (new FeedParser())->parse(<<<'XML'
            <?xml version="1.0"?>
            <!DOCTYPE rss PUBLIC "-//Netscape Communications//DTD RSS 0.91//EN" "http://my.netscape.com/publish/formats/rss-0.91.dtd">
            <rss version="0.91"><channel><title>Legacy</title><item><title>Old</title><link>https://example.test/old</link></item></channel></rss>
            XML);

        self::assertSame('Legacy', $feed->title);
        self::assertCount(1, $feed->items);
    }

    public function testItRejectsInternalEntitiesHiddenInUtf16(): void
    {
        $xml = "\xFF\xFE".mb_convert_encoding('<?xml version="1.0" encoding="UTF-16"?><!DOCTYPE rss [<!ENTITY a "boom">]><rss version="2.0"><channel><title>&a;</title></channel></rss>', 'UTF-16LE', 'UTF-8');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('DOCTYPE');
        (new FeedParser())->parse($xml);
    }
}
