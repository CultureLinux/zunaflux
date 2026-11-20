<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use App\Domain\Feed\FeedDiscovery;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * The only way the application talks to the outside world on behalf of a user-supplied address.
 *
 * Every hop is validated against private and reserved networks, the resolved address is pinned
 * (no second DNS lookup, so no DNS rebinding), redirects are followed by hand and re-validated,
 * proxies are ignored, and the decoded body is capped while it streams.
 */
final readonly class SafeHttpClient
{
    public const USER_AGENT = 'Zunaflux/1.0 (+https://zunaflux.clinux.fr/contact)';

    public function __construct(private UrlSafetyValidator $validator, private HttpClientInterface $client)
    {
    }

    /**
     * @param array{
     *     method?: string,
     *     headers?: array<string, string>,
     *     body?: array<string, scalar|null>|string|null,
     *     max_bytes?: int,
     *     max_redirects?: int,
     *     timeout?: float,
     *     max_duration?: float,
     *     schemes?: list<string>,
     *     ipv4_only?: bool,
     * } $options max_redirects = 0 returns a redirect response as is, without following it
     *
     * @throws \InvalidArgumentException when an address is refused by the safety policy
     * @throws HttpFetchFailed           on transport errors, oversized bodies and bad redirects
     */
    public function request(string $url, array $options = []): SafeResponse
    {
        $maxRedirects = $options['max_redirects'] ?? 3;
        $current = $url;
        for ($hop = 0;; ++$hop) {
            $this->assertScheme($current, $options['schemes'] ?? ['http', 'https']);
            $ips = $this->validator->assertAllowed($current);
            if (($options['ipv4_only'] ?? false) && [] !== array_filter($ips, static fn (string $ip): bool => !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4))) {
                throw new \InvalidArgumentException('Unsupported address.');
            }
            $response = $this->send($current, $ips[0], $options);
            try {
                $status = $response->getStatusCode();
                $headers = $this->flatten($response->getHeaders(false));
            } catch (TransportExceptionInterface $error) {
                throw new HttpFetchFailed('Request failed: '.$error->getMessage(), HttpFailure::Transport, $error);
            }
            $location = $headers['location'] ?? null;
            if ($maxRedirects > 0 && $status >= 300 && $status < 400 && 304 !== $status && null !== $location) {
                $response->cancel();
                if ($hop >= $maxRedirects) {
                    throw new HttpFetchFailed('Redirect limit exceeded.', HttpFailure::TooManyRedirects);
                }
                $current = (new FeedDiscovery())->resolve(trim($location), $current) ?? throw new HttpFetchFailed('Redirect target is not absolute.', HttpFailure::BadRedirect);

                continue;
            }

            return new SafeResponse($status, $headers, $this->readBody($response, $status, $options['max_bytes'] ?? 5_242_880), $current);
        }
    }

    /** @param array<string, mixed> $options */
    private function send(string $url, string $ip, array $options): ResponseInterface
    {
        $host = trim((string) parse_url($url, PHP_URL_HOST), '[]');
        $request = [
            'headers' => ['User-Agent' => self::USER_AGENT, ...($options['headers'] ?? [])],
            // Pin the validated address and never follow redirects or proxies on our own.
            'resolve' => [$host => $ip],
            'max_redirects' => 0,
            'no_proxy' => '*',
            'timeout' => $options['timeout'] ?? 5.0,
            'max_duration' => $options['max_duration'] ?? 15.0,
            'buffer' => false,
        ];
        if (isset($options['body'])) {
            $request['body'] = $options['body'];
        }

        try {
            return $this->client->request($options['method'] ?? 'GET', $url, $request);
        } catch (TransportExceptionInterface $error) {
            throw new HttpFetchFailed('Request failed: '.$error->getMessage(), HttpFailure::Transport, $error);
        }
    }

    private function readBody(ResponseInterface $response, int $status, int $maxBytes): string
    {
        $body = '';
        try {
            foreach ($this->client->stream($response) as $chunk) {
                if ($chunk->isTimeout()) {
                    $response->cancel();
                    throw new HttpFetchFailed('Request failed: operation timed out.', HttpFailure::Transport);
                }
                $body .= $chunk->getContent();
                if (strlen($body) > $maxBytes) {
                    $response->cancel();
                    throw new HttpFetchFailed('Response exceeds the size limit.', HttpFailure::TooLarge);
                }
            }
        } catch (TransportExceptionInterface $error) {
            throw new HttpFetchFailed('Request failed: '.$error->getMessage(), HttpFailure::Transport, $error);
        }

        return $body;
    }

    /** @param list<string> $schemes */
    private function assertScheme(string $url, array $schemes): void
    {
        if (!in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), $schemes, true)) {
            throw new \InvalidArgumentException('The URL must be an absolute HTTP(S) URL.');
        }
    }

    /**
     * @param array<string, list<string>> $headers
     *
     * @return array<string, string>
     */
    private function flatten(array $headers): array
    {
        $flat = [];
        foreach ($headers as $name => $values) {
            $flat[strtolower($name)] = (string) ($values[0] ?? '');
        }

        return $flat;
    }
}
