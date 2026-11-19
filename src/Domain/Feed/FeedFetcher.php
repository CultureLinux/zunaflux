<?php

declare(strict_types=1);

namespace App\Domain\Feed;

final readonly class FeedFetcher implements FeedFetcherInterface
{
    private const int MAX_BYTES = 5_242_880;
    private const int MAX_REDIRECTS = 3;

    public function __construct(private UrlSafetyValidator $urlSafetyValidator)
    {
    }

    public function fetch(string $url, ?string $etag = null, ?string $lastModified = null): FetchedFeed
    {
        $currentUrl = $url;
        for ($redirects = 0; $redirects <= self::MAX_REDIRECTS; ++$redirects) {
            $ips = $this->urlSafetyValidator->assertAllowed($currentUrl);
            $curl = curl_init($currentUrl);
            if (false === $curl) {
                throw new \RuntimeException('Unable to initialise HTTP client.');
            }

            $headers = ['Accept: application/rss+xml, application/atom+xml, application/xml, text/xml;q=0.9', 'User-Agent: Zunaflux/1.0 (+https://zunaflux.clinux.fr/contact)'];
            if ($etag) {
                $headers[] = 'If-None-Match: '.$etag;
            }
            if ($lastModified) {
                $headers[] = 'If-Modified-Since: '.$lastModified;
            }
            $host = (string) parse_url($currentUrl, PHP_URL_HOST);
            $port = (int) (parse_url($currentUrl, PHP_URL_PORT) ?: ('https' === parse_url($currentUrl, PHP_URL_SCHEME) ? 443 : 80));
            $resolve = array_map(static fn (string $ip): string => $host.':'.$port.':'.$ip, $ips);

            // Filled by the cURL callbacks. The body is capped while it streams
            // (after decompression), so an oversized or compressed-bomb response never
            // reaches memory in full.
            $responseHeaders = '';
            $body = '';
            $tooLarge = false;

            curl_setopt_array($curl, [
                CURLOPT_HEADERFUNCTION => static function (\CurlHandle $handle, string $line) use (&$responseHeaders): int {
                    if (str_starts_with($line, 'HTTP/')) {
                        $responseHeaders = '';
                    }
                    $responseHeaders .= $line;

                    return strlen($line);
                },
                CURLOPT_WRITEFUNCTION => static function (\CurlHandle $handle, string $chunk) use (&$body, &$tooLarge): int {
                    if (strlen($body) + strlen($chunk) > self::MAX_BYTES) {
                        $tooLarge = true;

                        return 0;
                    }
                    $body .= $chunk;

                    return strlen($chunk);
                },
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_MAXREDIRS => 0,
                CURLOPT_ENCODING => '',
                CURLOPT_PROXY => '',
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_RESOLVE => $resolve,
            ]);
            $ok = curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $error = curl_error($curl);
            curl_close($curl);

            if ($tooLarge) {
                throw new \RuntimeException('Feed response exceeds the size limit.');
            }
            if (false === $ok) {
                throw new \RuntimeException('Feed request failed: '.$error);
            }

            if ($status >= 300 && $status < 400 && 304 !== $status && preg_match('/^Location:\s*(.+)$/im', $responseHeaders, $matches)) {
                $currentUrl = $this->resolveLocation($currentUrl, trim($matches[1]));

                continue;
            }
            if (!in_array($status, [200, 304], true)) {
                throw new \RuntimeException('Feed request returned HTTP '.$status.'.');
            }

            return new FetchedFeed($status, 304 === $status ? null : $body, $this->header($responseHeaders, 'ETag'), $this->header($responseHeaders, 'Last-Modified'), $currentUrl);
        }

        throw new \RuntimeException('Feed redirect limit exceeded.');
    }

    /**
     * Resolves a Location header (RFC 9110 allows relative references) against the
     * current URL. The result is validated again by UrlSafetyValidator on the next hop.
     */
    private function resolveLocation(string $base, string $location): string
    {
        $baseParts = parse_url($base);
        if (false === $baseParts || !isset($baseParts['scheme'], $baseParts['host'])) {
            throw new \RuntimeException('Feed redirect is not absolute.');
        }
        $origin = strtolower($baseParts['scheme']).'://'.$baseParts['host'].(isset($baseParts['port']) ? ':'.$baseParts['port'] : '');

        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $location)) {
            $resolved = $location;
        } elseif (str_starts_with($location, '//')) {
            $resolved = strtolower($baseParts['scheme']).':'.$location;
        } elseif (str_starts_with($location, '/')) {
            $resolved = $origin.$location;
        } else {
            $directory = preg_replace('#[^/]*$#', '', $baseParts['path'] ?? '/') ?: '/';
            $resolved = $origin.$directory.$location;
        }

        if (!filter_var($resolved, FILTER_VALIDATE_URL)) {
            throw new \RuntimeException('Feed redirect is not absolute.');
        }

        return $resolved;
    }

    private function header(string $headers, string $name): ?string
    {
        return preg_match('/^'.preg_quote($name, '/').':\s*(.+)$/im', $headers, $matches) ? trim($matches[1]) : null;
    }
}
