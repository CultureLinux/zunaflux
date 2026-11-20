<?php

declare(strict_types=1);

namespace App\Infrastructure\Reading;

use App\Infrastructure\Http\HttpFetchFailed;
use App\Infrastructure\Http\SafeHttpClient;

final readonly class FeedImageFetcher
{
    private const MAX_BYTES = 5_242_880;

    public function __construct(private SafeHttpClient $http)
    {
    }

    /**
     * @return array{body: string, type: string}
     *
     * @throws \InvalidArgumentException when the address is refused by the safety policy
     * @throws \RuntimeException         when the image is unavailable or is not a supported picture
     */
    public function fetch(string $url): array
    {
        try {
            // Public IPv4 only, no redirect: an image address that moves is simply unavailable.
            $response = $this->http->request($url, [
                'headers' => ['Accept' => 'image/png,image/jpeg,image/gif,image/webp'],
                'max_bytes' => self::MAX_BYTES,
                'max_redirects' => 0,
                'ipv4_only' => true,
            ]);
        } catch (HttpFetchFailed $error) {
            throw new \RuntimeException('Image unavailable.', 0, $error);
        }
        if (200 !== $response->status) {
            throw new \RuntimeException('Image unavailable.');
        }
        $info = @getimagesizefromstring($response->body);
        $type = $info['mime'] ?? '';
        if (!in_array($type, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true) || ($info[0] * $info[1]) > 40_000_000) {
            throw new \RuntimeException('Unsupported image.');
        }

        return ['body' => $response->body, 'type' => $type];
    }
}
