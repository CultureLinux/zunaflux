<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Application\Health\HealthCheck;
use App\Controller\HealthController;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

final class HealthControllerTest extends TestCase
{
    public function testItAnswers503WithoutDetailsWhenTheDatabaseIsDown(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willThrowException(new \RuntimeException('SQLSTATE[HY000] secret detail'));
        $logger = new class () extends AbstractLogger {
            /** @var list<string> */
            public array $messages = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->messages[] = (string) $message;
            }
        };

        $response = (new HealthController(new HealthCheck($connection), $logger))();

        self::assertSame(503, $response->getStatusCode());
        self::assertSame(['status' => 'error', 'database' => 'down'], json_decode((string) $response->getContent(), true));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertSame(['Health check failed.'], $logger->messages);
        self::assertStringNotContainsString('secret detail', (string) $response->getContent());
    }

    public function testItReportsAggregateQueueCountsWhenHealthy(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturnOnConsecutiveCalls(1, 2, 5);

        $response = (new HealthController(new HealthCheck($connection), $this->createStub(\Psr\Log\LoggerInterface::class)))();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['status' => 'ok', 'database' => 'up', 'queue' => ['pending' => 5, 'failed' => 2]], json_decode((string) $response->getContent(), true));
    }
}
