<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Mastodon;

use App\Application\Mastodon\AccessTokenCipher;
use App\Application\Mastodon\MastodonHttpClient;
use App\Application\Mastodon\MastodonInstance;
use App\Application\Mastodon\MastodonOAuthService;
use App\Entity\MastodonAccount;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class MastodonOAuthServiceTest extends TestCase
{
    public function testItPublishesTheSelectedVisibilityWithTheDecryptedToken(): void
    {
        $client = new RecordingMastodonHttpClient(['url' => 'https://mastodon.example/@alice/123']);
        $cipher = new AccessTokenCipher(base64_encode(str_repeat('a', SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));
        $account = new MastodonAccount(new User('alice@example.test'), 'https://mastodon.example', '1', 'alice', 'Alice', null, $cipher->encrypt('plain-access-token'));
        $service = new MastodonOAuthService($client, $cipher, $this->createMock(EntityManagerInterface::class), 'https://zunaflux.example');

        self::assertSame('https://mastodon.example/@alice/123', $service->publish($account, 'A useful article https://example.test/article', 'unlisted'));
        self::assertSame('POST', $client->method);
        self::assertSame('/api/v1/statuses', $client->path);
        self::assertSame(['status' => 'A useful article https://example.test/article', 'visibility' => 'unlisted'], $client->form);
        self::assertSame('plain-access-token', $client->accessToken);
    }

    public function testItRejectsADirectStatusWithoutAMention(): void
    {
        $client = new RecordingMastodonHttpClient(['url' => 'https://mastodon.example/@alice/123']);
        $cipher = new AccessTokenCipher(base64_encode(str_repeat('a', SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));
        $account = new MastodonAccount(new User('alice@example.test'), 'https://mastodon.example', '1', 'alice', 'Alice', null, $cipher->encrypt('plain-access-token'));
        $service = new MastodonOAuthService($client, $cipher, $this->createMock(EntityManagerInterface::class), 'https://zunaflux.example');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Un message direct doit mentionner au moins un destinataire.');

        $service->publish($account, 'Bonjour', 'direct');
    }
}

final class RecordingMastodonHttpClient extends MastodonHttpClient
{
    public string $method = '';
    public string $path = '';
    /** @var array<string, string> */
    public array $form = [];
    public ?string $accessToken = null;

    /** @param array<string, mixed> $response */
    public function __construct(private array $response) {}

    /** @param array<string, string> $form */
    public function request(MastodonInstance $instance, string $method, string $path, array $form = [], ?string $accessToken = null): array
    {
        $this->method = $method;
        $this->path = $path;
        $this->form = $form;
        $this->accessToken = $accessToken;

        return $this->response;
    }
}
