<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Security\CspNonce;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Baseline response headers, applied here rather than in the web server so they also
 * cover installations outside Docker. A header already set by a controller is kept
 * (the image relay sets its own Content-Security-Policy).
 *
 * The script/style policy is sent in Report-Only mode first: violations are logged by
 * CspReportController and nothing is blocked. Enforcing it is a later, deliberate step.
 * HSTS is left to the TLS-terminating proxy.
 */
final class SecurityHeadersSubscriber implements EventSubscriberInterface
{
    private const HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'DENY',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Content-Security-Policy' => "frame-ancestors 'none'",
    ];

    public function __construct(
        private readonly CspNonce $nonce,
        #[Autowire('%env(default:app.push_empty:MATOMO_URL)%')] private readonly string $matomoUrl = '',
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => 'onResponse'];
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $headers = $event->getResponse()->headers;
        foreach (self::HEADERS as $name => $value) {
            if (!$headers->has($name)) {
                $headers->set($name, $value);
            }
        }
        if (!$headers->has('Content-Security-Policy-Report-Only') && $this->isHtml($event)) {
            $headers->set('Content-Security-Policy-Report-Only', $this->reportOnlyPolicy());
        }
    }

    /** Content-Type is only filled in by Response::prepare(), after this listener: fall back on the request format. */
    private function isHtml(ResponseEvent $event): bool
    {
        $response = $event->getResponse();
        $type = (string) $response->headers->get('Content-Type');
        if ('' !== $type) {
            return str_starts_with($type, 'text/html');
        }

        return 'html' === $event->getRequest()->getRequestFormat('html') && !$response->isRedirection() && !$response->isEmpty() && !$response instanceof \Symfony\Component\HttpFoundation\StreamedResponse && !$response instanceof \Symfony\Component\HttpFoundation\BinaryFileResponse;
    }

    private function reportOnlyPolicy(): string
    {
        $matomo = '';
        $parts = parse_url(trim($this->matomoUrl));
        if (is_array($parts) && isset($parts['scheme'], $parts['host']) && in_array($parts['scheme'], ['http', 'https'], true)) {
            $matomo = ' '.$parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
        }

        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-".$this->nonce->value()."'".$matomo,
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data:".$matomo,
            "font-src 'self'",
            "connect-src 'self'".$matomo,
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
            'report-uri /csp-report',
        ]);
    }
}
