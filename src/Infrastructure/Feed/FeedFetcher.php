<?php

declare(strict_types=1);

namespace App\Infrastructure\Feed;

use App\Domain\Feed\FeedFetcherInterface;
use App\Domain\Feed\FetchedFeed;
use App\Infrastructure\Http\SafeHttpClient;

final readonly class FeedFetcher implements FeedFetcherInterface
{
    private const int MAX_BYTES = 5_242_880;
    private const int MAX_REDIRECTS = 3;

    public function __construct(private SafeHttpClient $http)
    {
    }

    /**
     * @throws \InvalidArgumentException                                  when an address is refused by the safety policy
     * @throws \App\Infrastructure\Http\HttpFetchFailed|\RuntimeException on transport errors and unexpected statuses
     */
    public function fetch(string $url, ?string $etag = null, ?string $lastModified = null): FetchedFeed
    {
        $headers = ['Accept' => 'application/rss+xml, application/atom+xml, application/xml, text/xml;q=0.9'];
        if ($etag) {
            $headers['If-None-Match'] = $etag;
        }
        if ($lastModified) {
            $headers['If-Modified-Since'] = $lastModified;
        }
        $response = $this->http->request($url, ['headers' => $headers, 'max_bytes' => self::MAX_BYTES, 'max_redirects' => self::MAX_REDIRECTS]);
        if (!in_array($response->status, [200, 304], true)) {
            throw new \RuntimeException('Feed request returned HTTP '.$response->status.'.');
        }

        return new FetchedFeed(
            $response->status,
            304 === $response->status ? null : $response->body,
            $response->header('ETag'),
            $response->header('Last-Modified'),
            $response->url,
        );
    }
}
