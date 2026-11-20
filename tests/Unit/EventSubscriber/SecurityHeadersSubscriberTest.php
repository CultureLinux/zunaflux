<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventSubscriber;

use App\EventSubscriber\SecurityHeadersSubscriber;
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

    private function dispatch(Response $response, int $type): Response
    {
        $kernel = $this->createStub(HttpKernelInterface::class);
        (new SecurityHeadersSubscriber())->onResponse(new ResponseEvent($kernel, new Request(), $type, $response));

        return $response;
    }
}
