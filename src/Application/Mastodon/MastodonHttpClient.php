<?php

declare(strict_types=1);

namespace App\Application\Mastodon;

use App\Infrastructure\Http\HttpFetchFailed;
use App\Infrastructure\Http\SafeHttpClient;

class MastodonHttpClient
{
    public function __construct(private readonly SafeHttpClient $http)
    {
    }

    /**
     * @param array<string, scalar|null> $form
     *
     * @return array<string, mixed>
     */
    public function request(MastodonInstance $instance, string $method, string $path, array $form = [], ?string $accessToken = null): array
    {
        $headers = ['Accept' => 'application/json'];
        if (null !== $accessToken) {
            $headers['Authorization'] = 'Bearer '.$accessToken;
        }
        try {
            $response = $this->http->request($instance->endpoint($path), [
                'method' => $method,
                'headers' => $headers,
                'body' => [] === $form ? null : $form,
                'schemes' => ['https'],
                'max_redirects' => 0,
                'max_bytes' => 1_048_576,
            ]);
        } catch (\InvalidArgumentException $error) {
            throw new \RuntimeException('L’instance Mastodon ne résout pas vers une adresse publique.', 0, $error);
        } catch (HttpFetchFailed $error) {
            throw new \RuntimeException('La connexion à Mastodon a échoué.', 0, $error);
        }
        try {
            $data = json_decode($response->body, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \RuntimeException('Réponse Mastodon invalide.');
        }
        if ($response->status < 200 || $response->status >= 300 || !is_array($data)) {
            throw new MastodonApiException($response->status);
        }

        return $data;
    }
}
