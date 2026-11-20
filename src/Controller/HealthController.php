<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Health\HealthCheck;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Unauthenticated liveness/readiness probe: aggregate numbers only, no identifiers or messages. */
final class HealthController
{
    public function __construct(private readonly HealthCheck $health, private readonly LoggerInterface $logger)
    {
    }

    #[Route('/healthz', name: 'app_healthz', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        try {
            $queue = $this->health->queues();
        } catch (\Throwable $error) {
            $this->logger->error('Health check failed.', ['exception' => $error]);

            return $this->respond(['status' => 'error', 'database' => 'down'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return $this->respond(['status' => 'ok', 'database' => 'up', 'queue' => $queue], Response::HTTP_OK);
    }

    /** @param array<string, mixed> $payload */
    private function respond(array $payload, int $status): JsonResponse
    {
        $response = new JsonResponse($payload, $status);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
