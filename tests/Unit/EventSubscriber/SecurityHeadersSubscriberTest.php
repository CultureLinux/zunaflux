<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventSubscriber;

use App\EventSubscriber\SecurityHeadersSubscriber;
use App\Security\CspNonce;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class SecurityHeadersSubscriberTest extends TestCase
{
    public function testBaselineHeadersAreAddedToMainResponses(): void
    {
        $response = $this->dispatch(new Response(), HttpKernelInterface::MAIN_REQUEST);

        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame('DENY', $response->headers->get('X-Frame-Options'));
        self::assertSame('strict-origin-when-cross-origin', $response->headers->get('Referrer-Policy'));
        self::assertSame("frame-ancestors 'none'", $response->headers->get('Content-Security-Policy'));
    }

    public function testHeadersSetByTheControllerAreKept(): void
    {
        $response = new Response();
        $response->headers->set('Content-Security-Policy', 'sandbox');

        self::assertSame('sandbox', $this->dispatch($response, HttpKernelInterface::MAIN_REQUEST)->headers->get('Content-Security-Policy'));
    }

    public function testSubRequestsAreLeftAlone(): void
    {
        self::assertFalse($this->dispatch(new Response(), HttpKernelInterface::SUB_REQUEST)->headers->has('X-Frame-Options'));
    }

    public function testHtmlPagesGetAReportOnlyPolicyCarryingTheRequestNonce(): void
    {
        $nonce = new CspNonce();
        $response = new Response('<html>', 200, ['Content-Type' => 'text/html; charset=UTF-8']);

        $policy = (string) $this->dispatch($response, HttpKernelInterface::MAIN_REQUEST, $nonce)->headers->get('Content-Security-Policy-Report-Only');

        self::assertStringContainsString("script-src 'self' 'nonce-".$nonce->value()."'", $policy);
        self::assertStringContainsString("object-src 'none'", $policy);
        self::assertStringContainsString("frame-ancestors 'none'", $policy);
        self::assertStringContainsString('report-uri /csp-report', $policy);
        self::assertStringNotContainsString('unsafe-eval', $policy);
        // The enforced header is still only the framing protection.
        self::assertSame("frame-ancestors 'none'", $response->headers->get('Content-Security-Policy'));
    }

    public function testTheAnalyticsOriginIsAllowedOnlyWhenConfiguredAndAssetsGetNoReportOnlyPolicy(): void
    {
        $html = new Response('<html>', 200, ['Content-Type' => 'text/html']);
        $policy = (string) $this->dispatch($html, HttpKernelInterface::MAIN_REQUEST, null, 'https://stats.example.org/matomo/')->headers->get('Content-Security-Policy-Report-Only');
        self::assertStringContainsString("script-src 'self' 'nonce-", $policy);
        self::assertStringContainsString("connect-src 'self' https://stats.example.org", $policy);

        $without = (string) $this->dispatch(new Response('<html>', 200, ['Content-Type' => 'text/html']), HttpKernelInterface::MAIN_REQUEST)->headers->get('Content-Security-Policy-Report-Only');
        self::assertStringNotContainsString('stats.example.org', $without);

        self::assertFalse($this->dispatch(new Response('{}', 200, ['Content-Type' => 'application/json']), HttpKernelInterface::MAIN_REQUEST)->headers->has('Content-Security-Policy-Report-Only'));
    }

    public function testAnHtmlBodyWithoutAContentTypeYetIsStillCovered(): void
    {
        $page = new Response('<html></html>');
        self::assertTrue($this->dispatch($page, HttpKernelInterface::MAIN_REQUEST)->headers->has('Content-Security-Policy-Report-Only'));

        self::assertFalse($this->dispatch(new Response('', 204), HttpKernelInterface::MAIN_REQUEST)->headers->has('Content-Security-Policy-Report-Only'));
        self::assertFalse($this->dispatch(new Response('', 302, ['Location' => '/']), HttpKernelInterface::MAIN_REQUEST)->headers->has('Content-Security-Policy-Report-Only'));
    }

    public function testTheNonceChangesWithEveryRequest(): void
    {
        $nonce = new CspNonce();
        $first = $nonce->value();
        self::assertSame($first, $nonce->value());
        $nonce->reset();
        self::assertNotSame($first, $nonce->value());
    }

    private function dispatch(Response $response, int $type, ?CspNonce $nonce = null, string $matomo = ''): Response
    {
        $kernel = $this->createStub(HttpKernelInterface::class);
        (new SecurityHeadersSubscriber($nonce ?? new CspNonce(), $matomo))->onResponse(new ResponseEvent($kernel, new Request(), $type, $response));

        return $response;
    }
}
