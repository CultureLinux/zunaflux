<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Mastodon;

use App\Application\Mastodon\MastodonInstance;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MastodonInstanceTest extends TestCase
{
    public function testItNormalizesAnHttpsOrigin(): void
    {
        self::assertSame('https://piaille.fr', MastodonInstance::fromInput('https://piaille.fr/')->origin());
    }

    /** @dataProvider invalidUrls */
    #[DataProvider('invalidUrls')]
    public function testItRejectsNonOriginOrNonHttpsUrls(string $url): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MastodonInstance::fromInput($url);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidUrls(): iterable
    {
        yield 'http' => ['http://piaille.fr'];
        yield 'credentials' => ['https://user:secret@piaille.fr'];
        yield 'path' => ['https://piaille.fr/mastodon'];
        yield 'port' => ['https://piaille.fr:8443'];
        yield 'fragment' => ['https://piaille.fr/#fragment'];
    }
}
