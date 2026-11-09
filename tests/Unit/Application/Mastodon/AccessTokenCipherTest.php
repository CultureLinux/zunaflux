<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Mastodon;

use App\Application\Mastodon\AccessTokenCipher;
use PHPUnit\Framework\TestCase;

final class AccessTokenCipherTest extends TestCase
{
    public function testItEncryptsAndDecryptsAnAccessToken(): void
    {
        $cipher = new AccessTokenCipher(base64_encode(str_repeat('a', SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));

        $encrypted = $cipher->encrypt('mastodon-access-token');

        self::assertNotSame('mastodon-access-token', $encrypted);
        self::assertSame('mastodon-access-token', $cipher->decrypt($encrypted));
    }

    public function testItRejectsAnInvalidKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AccessTokenCipher('invalid');
    }
}
