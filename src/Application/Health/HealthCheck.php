<?php

declare(strict_types=1);

namespace App\Application\Health;

use Doctrine\DBAL\Connection;

/** Readiness probe: the database answers and the message queues can be counted. */
final readonly class HealthCheck
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @return array{pending: int, failed: int}
     *
     * @throws \Throwable when the database does not answer
     */
    public function queues(): array
    {
        $this->connection->fetchOne('SELECT 1');
        $failed = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM messenger_messages WHERE queue_name = ?', ['failed']);
        $pending = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM messenger_messages WHERE queue_name <> ?', ['failed']);

        return ['pending' => $pending, 'failed' => $failed];
    }
}
