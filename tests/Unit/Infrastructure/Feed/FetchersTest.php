<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Feed;

use App\Application\Exception\DiscoveryFailed;
use App\Application\Mastodon\MastodonApiException;
use App\Application\Mastodon\MastodonHttpClient;
use App\Application\Mastodon\MastodonInstance;
use App\Infrastructure\Feed\DiscoveryFetcher;
use App\Infrastructure\Feed\FeedFetcher;
use App\Infrastructure\Http\HttpFetchFailed;
use App\Infrastructure\Http\SafeHttpClient;
use App\Infrastructure\Http\UrlSafetyValidator;
use App\Infrastructure\Reading\FeedImageFetcher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class FetchersTest extends TestCase
{
    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    private array $requests = [];

    public function testTheFeedFetcherSendsValidatorsAndReturnsTheDocumentWithItsValidators(): void
    {
        $fetcher = new FeedFetcher($this->http([new MockResponse('<rss/>', ['response_headers' => ['ETag' => '"v2"', 'Last-Modified' => 'Wed, 01 Jan 2031 00:00:00 GMT']])]));

        $feed = $fetcher->fetch('http://93.184.216.34/rss', '"v1"', 'Tue, 31 Dec 2030 00:00:00 GMT');

        self::assertSame(200, $feed->statusCode);
        self::assertSame('<rss/>', $feed->body);
        self::assertSame('"v2"', $feed->etag);
        self::assertSame('Wed, 01 Jan 2031 00:00:00 GMT', $feed->lastModified);
        $headers = implode("\n", $this->requests[0]['options']['headers']);
        self::assertStringContainsString('If-None-Match: "v1"', $headers);
        self::assertStringContainsString('If-Modified-Since: Tue, 31 Dec 2030 00:00:00 GMT', $headers);
    }

    public function testANotModifiedAnswerHasNoBodyAndARedirectUpdatesTheFinalUrl(): void
    {
        $notModified = new FeedFetcher($this->http([new MockResponse('', ['http_code' => 304])]));
        $feed = $notModified->fetch('http://93.184.216.34/rss', '"v1"');
        self::assertSame(304, $feed->statusCode);
        self::assertNull($feed->body);

        $moved = new FeedFetcher($this->http([
            new MockResponse('', ['http_code' => 301, 'response_headers' => ['Location' => 'https://93.184.216.34/new']]),
            new MockResponse('<rss/>'),
        ]));
        self::assertSame('https://93.184.216.34/new', $moved->fetch('http://93.184.216.34/old')->url);
    }

    public function testTheFeedFetcherRejectsErrorStatusesAndOversizedFeeds(): void
    {
        try {
            (new FeedFetcher($this->http([new MockResponse('', ['http_code' => 503])])))->fetch('http://93.184.216.34/rss');
            self::fail('A 503 must fail.');
        } catch (\RuntimeException $error) {
            self::assertSame('Feed request returned HTTP 503.', $error->getMessage());
        }
        $this->expectException(HttpFetchFailed::class);
        (new FeedFetcher($this->http([new MockResponse(str_repeat('x', 5_242_881))])))->fetch('http://93.184.216.34/rss');
    }

    public function testDiscoveryReturnsThePageAndTranslatesFailuresIntoUserMessages(): void
    {
        $page = (new DiscoveryFetcher($this->http([new MockResponse('<html/>')])))->fetch('http://93.184.216.34/');
        self::assertSame(['url' => 'http://93.184.216.34/', 'body' => '<html/>'], $page);

        $cases = [
            [new MockResponse('', ['http_code' => 403]), 'Le site refuse l’accès (HTTP 403). Il utilise peut-être une protection contre les robots.', [], 403],
            [new MockResponse('', ['http_code' => 500]), 'Le site a répondu avec le code HTTP %status%.', ['%status%' => 500], 0],
            [new MockResponse(str_repeat('x', 2_000_001)), 'La page dépasse la limite de téléchargement de 2 Mo.', [], 0],
            [new MockResponse('', ['error' => 'Connection refused']), 'La connexion au site a échoué.', [], 0],
        ];
        foreach ($cases as [$response, $key, $parameters, $code]) {
            try {
                (new DiscoveryFetcher($this->http([$response])))->fetch('http://93.184.216.34/');
                self::fail($key.' expected');
            } catch (DiscoveryFailed $error) {
                self::assertSame([$key, $parameters, $code], [$error->getTranslationKey(), $error->getTranslationParameters(), $error->getCode()]);
            }
        }
    }

    public function testTheImageRelayAcceptsRealImagesAndRefusesEverythingElse(): void
    {
        $png = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', true);
        $image = (new FeedImageFetcher($this->http([new MockResponse($png)])))->fetch('http://93.184.216.34/a.png');
        self::assertSame('image/png', $image['type']);
        self::assertSame($png, $image['body']);

        foreach ([new MockResponse('<html>not an image</html>'), new MockResponse('', ['http_code' => 404]), new MockResponse('', ['http_code' => 302, 'response_headers' => ['Location' => 'http://93.184.216.34/b.png']])] as $response) {
            try {
                (new FeedImageFetcher($this->http([$response])))->fetch('http://93.184.216.34/a.png');
                self::fail('Only a 200 answer carrying a supported picture is relayed.');
            } catch (\RuntimeException) {
                self::addToAssertionCount(1);
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        (new FeedImageFetcher($this->http([])))->fetch('http://[2606:2800:220:1:248:1893:25c8:1946]/a.png');
    }

    public function testTheMastodonClientSpeaksHttpsWithABearerTokenAndDecodesJson(): void
    {
        $client = new MastodonHttpClient($this->http([new MockResponse('{"id":"1"}')]));
        $data = $client->request(MastodonInstance::fromInput('https://mastodon.example'), 'POST', '/api/v1/statuses', ['status' => 'Hello'], 'secret');

        self::assertSame(['id' => '1'], $data);
        self::assertSame('POST', $this->requests[0]['method']);
        self::assertSame('https://mastodon.example/api/v1/statuses', $this->requests[0]['url']);
        self::assertStringContainsString('Authorization: Bearer secret', implode("\n", $this->requests[0]['options']['headers']));
        self::assertStringContainsString('status=Hello', (string) $this->requests[0]['options']['body']);
    }

    public function testTheMastodonClientReportsApiErrorsAndBrokenAnswers(): void
    {
        $instance = MastodonInstance::fromInput('https://mastodon.example');
        try {
            (new MastodonHttpClient($this->http([new MockResponse('{"error":"no"}', ['http_code' => 401])])))->request($instance, 'GET', '/api/v1/accounts/verify_credentials', [], 't');
            self::fail('A 401 must raise the API exception.');
        } catch (MastodonApiException $error) {
            self::assertSame(401, $error->getStatusCode());
        }
        foreach ([[new MockResponse('<html>'), 'Réponse Mastodon invalide.'], [new MockResponse('', ['error' => 'timeout']), 'La connexion à Mastodon a échoué.']] as [$response, $message]) {
            try {
                (new MastodonHttpClient($this->http([$response])))->request($instance, 'GET', '/api/v1/instance');
                self::fail($message);
            } catch (\RuntimeException $error) {
                self::assertSame($message, $error->getMessage());
            }
        }
    }

    /** @param list<MockResponse> $responses */
    private function http(array $responses): SafeHttpClient
    {
        $this->requests = [];
        $queue = $responses;
        $mock = new MockHttpClient(function (string $method, string $url, array $options) use (&$queue): MockResponse {
            $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

            return array_shift($queue) ?? new MockResponse('unexpected', ['http_code' => 500]);
        });

        return new SafeHttpClient(new UrlSafetyValidator(static fn (string $host): array => ['93.184.216.34']), $mock);
    }
}
