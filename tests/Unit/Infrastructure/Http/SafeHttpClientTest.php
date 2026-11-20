<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Http;

use App\Infrastructure\Http\HttpFailure;
use App\Infrastructure\Http\HttpFetchFailed;
use App\Infrastructure\Http\SafeHttpClient;
use App\Infrastructure\Http\UrlSafetyValidator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class SafeHttpClientTest extends TestCase
{
    private const PUBLIC_URL = 'http://93.184.216.34/feed.xml';

    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    private array $requests = [];

    public function testItReturnsStatusLowerCasedHeadersBodyAndFinalUrl(): void
    {
        $client = $this->client([new MockResponse('<rss/>', ['http_code' => 200, 'response_headers' => ['ETag' => '"abc"', 'Content-Type' => 'application/rss+xml']])]);

        $response = $client->request(self::PUBLIC_URL);

        self::assertSame(200, $response->status);
        self::assertSame('<rss/>', $response->body);
        self::assertSame('"abc"', $response->header('ETag'));
        self::assertSame('application/rss+xml', $response->headers['content-type']);
        self::assertSame(self::PUBLIC_URL, $response->url);
        self::assertNull($response->header('X-Missing'));
    }

    public function testTheValidatedAddressIsPinnedAndProxiesAndAutomaticRedirectsAreOff(): void
    {
        $client = $this->client([new MockResponse('ok')]);

        $client->request('http://93.184.216.34/page', ['headers' => ['Accept' => 'text/html']]);

        $options = $this->requests[0]['options'];
        self::assertSame(['93.184.216.34' => '93.184.216.34'], $options['resolve']);
        self::assertSame(0, $options['max_redirects']);
        self::assertSame('*', $options['no_proxy']);
        $headers = implode("\n", $options['headers']);
        self::assertStringContainsString('Accept: text/html', $headers);
        self::assertStringContainsString('User-Agent: '.SafeHttpClient::USER_AGENT, $headers);
    }

    public function testRedirectsAreFollowedByHandAndResolvedAgainstTheCurrentUrl(): void
    {
        $client = $this->client([
            new MockResponse('', ['http_code' => 301, 'response_headers' => ['Location' => '/moved/feed.xml']]),
            new MockResponse('final'),
        ]);

        $response = $client->request('http://93.184.216.34/a/feed.xml');

        self::assertSame('final', $response->body);
        self::assertSame('http://93.184.216.34/moved/feed.xml', $response->url);
        self::assertCount(2, $this->requests);
    }

    public function testEveryRedirectHopIsRevalidatedAgainstPrivateNetworks(): void
    {
        $client = $this->client([new MockResponse('', ['http_code' => 302, 'response_headers' => ['Location' => 'http://127.0.0.1/admin']])]);

        $this->expectException(\InvalidArgumentException::class);
        try {
            $client->request(self::PUBLIC_URL);
        } finally {
            self::assertCount(1, $this->requests, 'The private target must never be requested.');
        }
    }

    public function testTooManyRedirectsAndUnusableLocationsAreReported(): void
    {
        $loop = array_fill(0, 5, new MockResponse('', ['http_code' => 302, 'response_headers' => ['Location' => '/again']]));
        try {
            $this->client($loop)->request(self::PUBLIC_URL, ['max_redirects' => 2]);
            self::fail('The redirect limit must apply.');
        } catch (HttpFetchFailed $error) {
            self::assertSame(HttpFailure::TooManyRedirects, $error->reason);
            self::assertStringContainsString('Redirect', $error->getMessage());
        }

        try {
            $this->client([new MockResponse('', ['http_code' => 302, 'response_headers' => ['Location' => 'javascript:alert(1)']])])->request(self::PUBLIC_URL);
            self::fail('A non-HTTP location must be refused.');
        } catch (HttpFetchFailed $error) {
            self::assertSame(HttpFailure::BadRedirect, $error->reason);
        }
    }

    public function testRedirectsCanBeLeftToTheCaller(): void
    {
        $client = $this->client([new MockResponse('', ['http_code' => 301, 'response_headers' => ['Location' => '/elsewhere']])]);

        $response = $client->request(self::PUBLIC_URL, ['max_redirects' => 0]);

        self::assertSame(301, $response->status);
        self::assertSame('/elsewhere', $response->header('Location'));
        self::assertCount(1, $this->requests);
    }

    public function testTheDecodedBodyIsCappedWhileItStreams(): void
    {
        $client = $this->client([new MockResponse([str_repeat('a', 600), str_repeat('b', 600)])]);

        try {
            $client->request(self::PUBLIC_URL, ['max_bytes' => 1000]);
            self::fail('An oversized body must be refused.');
        } catch (HttpFetchFailed $error) {
            self::assertSame(HttpFailure::TooLarge, $error->reason);
            self::assertStringContainsString('size limit', $error->getMessage());
        }
        self::assertSame('ok', $this->client([new MockResponse('ok')])->request(self::PUBLIC_URL, ['max_bytes' => 2])->body);
    }

    public function testTransportFailuresBecomeFetchFailuresThatKeepTheCause(): void
    {
        $client = $this->client([new MockResponse('', ['error' => 'Could not resolve host: nowhere.test'])]);

        try {
            $client->request(self::PUBLIC_URL);
            self::fail('A transport error must surface.');
        } catch (HttpFetchFailed $error) {
            self::assertSame(HttpFailure::Transport, $error->reason);
            self::assertStringContainsString('Could not resolve host', $error->getMessage());
            self::assertNotNull($error->getPrevious());
        }
    }

    public function testSchemesAndAddressFamiliesCanBeRestricted(): void
    {
        $client = $this->client([new MockResponse('ok')]);

        foreach ([['http://93.184.216.34/', ['schemes' => ['https']]], ['http://[2606:2800:220:1:248:1893:25c8:1946]/', ['ipv4_only' => true]]] as [$url, $options]) {
            try {
                $client->request($url, $options);
                self::fail($url.' must be refused.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        self::assertSame([], $this->requests);
    }

    public function testMethodAndFormBodyAreForwarded(): void
    {
        $client = $this->client([new MockResponse('{}')]);

        $client->request('https://93.184.216.34/api', ['method' => 'POST', 'body' => ['status' => 'hello'], 'headers' => ['Authorization' => 'Bearer t']]);

        self::assertSame('POST', $this->requests[0]['method']);
        self::assertStringContainsString('status=hello', (string) $this->requests[0]['options']['body']);
        self::assertStringContainsString('Authorization: Bearer t', implode("\n", $this->requests[0]['options']['headers']));
    }

    /** @param list<MockResponse> $responses */
    private function client(array $responses): SafeHttpClient
    {
        $this->requests = [];
        $queue = $responses;
        $mock = new MockHttpClient(function (string $method, string $url, array $options) use (&$queue): MockResponse {
            $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

            return array_shift($queue) ?? new MockResponse('unexpected extra request', ['http_code' => 500]);
        });

        return new SafeHttpClient(new UrlSafetyValidator(), $mock);
    }
}
