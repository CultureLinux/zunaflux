<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Reading;

use App\Application\Reading\FeedHtmlSanitizer;
use PHPUnit\Framework\TestCase;

final class FeedHtmlSanitizerTest extends TestCase
{
    public function testPreservesEditorialMarkupAndSafeLinks(): void
    {
        $html = (new FeedHtmlSanitizer())->sanitize('<p>Bonjour <strong>lecteur</strong>. <a href="https://example.test/a">Source</a></p>');

        self::assertStringContainsString('<strong>lecteur</strong>', $html);
        self::assertStringContainsString('href="https://example.test/a"', $html);
        self::assertStringContainsString('rel="noopener noreferrer" target="_blank"', $html);
    }

    public function testRemovesExecutableContentUnsafeUrlsAndRemoteResources(): void
    {
        $html = (new FeedHtmlSanitizer())->sanitize('<p onclick="alert(1)">Texte<script>alert(1)</script><a href="javascript:alert(1)">lien</a><img src="https://example.test/tracker" onerror="alert(1)"><svg onload="alert(1)"></svg></p>');

        self::assertStringContainsString('Texte', $html);
        foreach (['onclick', 'script', 'javascript:', '<img', '<svg', 'alert(1)', 'example.test'] as $unsafe) {
            self::assertStringNotContainsString($unsafe, $html);
        }
    }
    public function testImagesAreOnlyRenderedThroughTheProvidedLocalUrl(): void
    {
        $sanitizer = new FeedHtmlSanitizer();
        $html = $sanitizer->sanitize('<p><img src="https://example.test/a.jpg" alt="Photo" onerror="alert(1)"></p>', static fn (int $index): string => '/articles/1/images/'.$index);

        self::assertStringContainsString('src="/articles/1/images/0"', $html);
        self::assertStringContainsString('alt="Photo"', $html);
        self::assertStringNotContainsString('https://example.test', $html);
        self::assertStringNotContainsString('onerror', $html);
        self::assertSame(['https://example.test/a.jpg'], $sanitizer->imageUrls('<img src="https://example.test/a.jpg">'));
    }

}
