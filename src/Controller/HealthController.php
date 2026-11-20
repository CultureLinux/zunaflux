<?php

declare(strict_types=1);

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Unauthenticated liveness/readiness probe: aggregate numbers only, no identifiers or messages. */
final class HealthController
{
    public function __construct(private readonly Connection $connection, private readonly LoggerInterface $logger)
    {
    }

    #[Route('/healthz', name: 'app_healthz', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        try {
            $this->connection->fetchOne('SELECT 1');
            $queues = $this->connection->fetchAllKeyValue(
                'SELECT queue_name, COUNT(*) FROM messenger_messages WHERE queue_name IN (?, ?) GROUP BY queue_name',
                ['async', 'failed'],
            );
        } catch (\Throwable $error) {
            $this->logger->error('Health check failed.', ['exception' => $error]);

            return $this->respond(['status' => 'error', 'database' => 'down'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return $this->respond([
            'status' => 'ok',
            'database' => 'up',
            'queue' => ['pending' => (int) ($queues['async'] ?? 0), 'failed' => (int) ($queues['failed'] ?? 0)],
        ], Response::HTTP_OK);
    }

    /** @param array<string, mixed> $payload */
    private function respond(array $payload, int $status): JsonResponse
    {
        $response = new JsonResponse($payload, $status);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
