<?php

declare(strict_types=1);

namespace App\Controller;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Receives Content-Security-Policy violation reports while the policy is in Report-Only mode.
 * Public by nature (browsers send it without credentials), so it is small, rate limited per IP
 * and only ever writes a trimmed line to the application log.
 */
final class CspReportController
{
    private const MAX_BYTES = 8192;

    public function __construct(
        private readonly LoggerInterface $logger,
        #[Autowire(service: 'limiter.csp_report')] private readonly RateLimiterFactoryInterface $limiter,
    ) {
    }

    #[Route('/csp-report', name: 'app_csp_report', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        if (!$this->limiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
            return new Response(status: Response::HTTP_TOO_MANY_REQUESTS);
        }
        if (!in_array($request->headers->get('Content-Type'), ['application/csp-report', 'application/reports+json', 'application/json'], true)) {
            return new Response(status: Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
        }
        $body = $request->getContent();
        if (strlen($body) > self::MAX_BYTES) {
            return new Response(status: Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }
        try {
            $payload = json_decode($body, true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return new Response(status: Response::HTTP_BAD_REQUEST);
        }
        $report = $payload['csp-report'] ?? $payload[0]['body'] ?? [];
        // First scalar value found under any of the given keys (the two report formats name them differently), trimmed.
        $field = static function (string ...$names) use ($report): string {
            foreach ($names as $name) {
                if (is_array($report) && isset($report[$name]) && is_scalar($report[$name])) {
                    return mb_substr((string) $report[$name], 0, 200);
                }
            }

            return '';
        };
        $this->logger->warning('CSP violation reported.', [
            'directive' => $field('violated-directive', 'effectiveDirective', 'effective-directive'),
            'blocked' => $field('blocked-uri', 'blockedURL'),
            // The query string may carry identifiers: only the path is kept.
            'document' => strtok($field('document-uri', 'documentURL'), '?') ?: '',
            'source' => strtok($field('source-file', 'sourceFile'), '?') ?: '',
            'line' => $field('line-number', 'lineNumber'),
        ]);

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
