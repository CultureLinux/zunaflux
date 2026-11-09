<?php

declare(strict_types=1);

namespace App\Application\Mastodon;

final class AccessTokenCipher
{
    private string $key;

    public function __construct(string $encodedKey)
    {
        $key = base64_decode($encodedKey, true);
        if (false === $key || SODIUM_CRYPTO_SECRETBOX_KEYBYTES !== strlen($key)) {
            throw new \InvalidArgumentException('ZUNAFLUX_MASTODON_TOKEN_KEY must be a base64-encoded 32-byte key.');
        }
        $this->key = $key;
    }

    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return base64_encode($nonce.sodium_crypto_secretbox($plaintext, $nonce, $this->key));
    }

    public function decrypt(string $ciphertext): string
    {
        $payload = base64_decode($ciphertext, true);
        if (false === $payload || strlen($payload) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \RuntimeException('Stored Mastodon token is invalid.');
        }
        $nonce = substr($payload, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $encryptedToken = substr($payload, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $token = sodium_crypto_secretbox_open($encryptedToken, $nonce, $this->key);
        if (false === $token) {
            throw new \RuntimeException('Stored Mastodon token cannot be decrypted.');
        }

        return $token;
    }
}
