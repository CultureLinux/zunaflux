<?php

declare(strict_types=1);

namespace App\Infrastructure\Feed;

use App\Application\Exception\DiscoveryFailed;
use App\Domain\Feed\PageFetcherInterface;
use App\Infrastructure\Http\HttpFailure;
use App\Infrastructure\Http\HttpFetchFailed;
use App\Infrastructure\Http\SafeHttpClient;

final readonly class DiscoveryFetcher implements PageFetcherInterface
{
    private const int MAX_BYTES = 2_000_000;

    public function __construct(private SafeHttpClient $http)
    {
    }

    /**
     * @return array{url: string, body: string}
     *
     * @throws \InvalidArgumentException when an address is refused by the safety policy
     * @throws DiscoveryFailed           with a user-facing, translatable message
     */
    public function fetch(string $url): array
    {
        try {
            $response = $this->http->request($url, [
                'headers' => ['Accept' => 'text/html, application/rss+xml, application/atom+xml, application/xml;q=0.9'],
                'max_bytes' => self::MAX_BYTES,
                'ipv4_only' => true,
            ]);
        } catch (HttpFetchFailed $error) {
            throw match ($error->reason) {
                HttpFailure::TooLarge => new DiscoveryFailed('La page dépasse la limite de téléchargement de 2 Mo.', previous: $error),
                HttpFailure::TooManyRedirects => new DiscoveryFailed('Trop de redirections.', previous: $error),
                HttpFailure::BadRedirect => new DiscoveryFailed('Redirection invalide.', previous: $error),
                HttpFailure::Transport => new DiscoveryFailed('La connexion au site a échoué.', previous: $error),
            };
        }
        if (403 === $response->status) {
            throw new DiscoveryFailed('Le site refuse l’accès (HTTP 403). Il utilise peut-être une protection contre les robots.', code: 403);
        }
        if (200 !== $response->status) {
            throw new DiscoveryFailed('Le site a répondu avec le code HTTP %status%.', ['%status%' => $response->status]);
        }

        return ['url' => $response->url, 'body' => $response->body];
    }
}
