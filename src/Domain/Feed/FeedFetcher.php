<?php

declare(strict_types=1);

namespace App\Domain\Feed;

final readonly class FeedFetcher
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
            if (false === $curl) throw new \RuntimeException('Unable to initialise HTTP client.');
            $headers = ['Accept: application/rss+xml, application/atom+xml, application/xml, text/xml;q=0.9', 'User-Agent: Zunaflux/1.0 (+https://zunaflux.clinux.fr/contact)'];
            if ($etag) $headers[] = 'If-None-Match: '.$etag;
            if ($lastModified) $headers[] = 'If-Modified-Since: '.$lastModified;
            $host = (string) parse_url($currentUrl, PHP_URL_HOST);
            $port = (int) (parse_url($currentUrl, PHP_URL_PORT) ?: ('https' === parse_url($currentUrl, PHP_URL_SCHEME) ? 443 : 80));
            $resolve = array_map(static fn (string $ip): string => $host.':'.$port.':'.$ip, $ips);
            curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 15, CURLOPT_MAXREDIRS => 0, CURLOPT_HTTPHEADER => $headers, CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_RESOLVE => $resolve]);
            $response = curl_exec($curl); $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE); $headerLength = (int) curl_getinfo($curl, CURLINFO_HEADER_SIZE);
            if (false === $response) { $error = curl_error($curl); curl_close($curl); throw new \RuntimeException('Feed request failed: '.$error); }
            curl_close($curl); $headerBlock = substr($response, 0, $headerLength); $body = substr($response, $headerLength);
            if (strlen($body) > self::MAX_BYTES) throw new \RuntimeException('Feed response exceeds the size limit.');
            if ($status >= 300 && $status < 400 && preg_match('/^Location:\s*(.+)$/im', $headerBlock, $matches)) { $currentUrl = trim($matches[1]); if (!filter_var($currentUrl, FILTER_VALIDATE_URL)) throw new \RuntimeException('Feed redirect is not absolute.'); continue; }
            if (!in_array($status, [200, 304], true)) throw new \RuntimeException('Feed request returned HTTP '.$status.'.');
            return new FetchedFeed($status, 304 === $status ? null : $body, $this->header($headerBlock, 'ETag'), $this->header($headerBlock, 'Last-Modified'), $currentUrl);
        }
        throw new \RuntimeException('Feed redirect limit exceeded.');
    }
    private function header(string $headers, string $name): ?string { return preg_match('/^'.preg_quote($name, '/').':\s*(.+)$/im', $headers, $matches) ? trim($matches[1]) : null; }
}
