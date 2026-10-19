<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Subscription;

use App\Application\Subscription\OpmlSubscriptionParser;
use PHPUnit\Framework\TestCase;

final class OpmlSubscriptionParserTest extends TestCase
{
    public function testItExtractsFeedUrlsAndTheirClosestFolder(): void
    {
        $parser = new OpmlSubscriptionParser();

        $subscriptions = $parser->parse(<<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <opml version="2.0"><body>
              <outline text="Blog"><outline text="Example" type="rss" xmlUrl="https://example.test/feed.xml" /></outline>
              <outline text="Standalone" type="rss" xmlUrl="https://standalone.test/rss" />
            </body></opml>
            XML);

        self::assertCount(2, $subscriptions);
        self::assertSame('https://example.test/feed.xml', $subscriptions[0]->url);
        self::assertSame('Blog', $subscriptions[0]->categoryName);
        self::assertSame('https://standalone.test/rss', $subscriptions[1]->url);
        self::assertNull($subscriptions[1]->categoryName);
    }

    public function testItRejectsExternalEntityDeclarations(): void
    {
        $parser = new OpmlSubscriptionParser();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('DOCTYPE');

        $parser->parse('<!DOCTYPE opml [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><opml version="2.0"/>');
    }
}
