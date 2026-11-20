<?php

declare(strict_types=1);

namespace App\Tests\Functional\Command;

use App\Entity\FeedIncident;
use App\Tests\Support\RollbackKernelTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class OperationsCommandsTest extends RollbackKernelTestCase
{
    public function testTheWorkerHealthcheckFollowsTheHeartbeat(): void
    {
        $tester = new CommandTester((new Application(self::$kernel))->find('app:worker:healthcheck'));

        $this->db->executeStatement("INSERT INTO messenger_worker_heartbeat (name, last_seen_at) VALUES ('async', NOW()) ON DUPLICATE KEY UPDATE last_seen_at = NOW()");
        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('worker alive', $tester->getDisplay());

        $this->db->executeStatement("UPDATE messenger_worker_heartbeat SET last_seen_at = '2000-01-01 00:00:00' WHERE name = 'async'");
        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('worker silent', $tester->getDisplay());

        $this->db->executeStatement("DELETE FROM messenger_worker_heartbeat WHERE name = 'async'");
        self::assertSame(Command::FAILURE, $tester->execute([]), 'No heartbeat at all is a silent worker.');
    }

    public function testTheStatusCommandSummarisesQueuesAndIncidentsWithoutLeakingData(): void
    {
        $feed = $this->feed();
        $this->subscribe($this->user(), $feed);
        $feed->recordFailure(new \RuntimeException('Feed request returned HTTP 503.'));
        $this->em->persist(new FeedIncident($feed, new \RuntimeException('Feed request returned HTTP 503.')));
        $this->em->flush();
        $this->db->executeStatement("INSERT INTO messenger_worker_heartbeat (name, last_seen_at) VALUES ('async', NOW()) ON DUPLICATE KEY UPDATE last_seen_at = NOW()");
        $this->db->executeStatement('DELETE FROM messenger_messages');
        $tester = new CommandTester((new Application(self::$kernel))->find('app:status'));

        self::assertSame(Command::SUCCESS, $tester->execute(['--json' => true]));
        $report = json_decode($tester->getDisplay(), true, 8, \JSON_THROW_ON_ERROR);
        self::assertSame('ok', $report['status']);
        self::assertSame('alive', $report['worker']);
        self::assertSame(['pending' => 0, 'failed' => 0], $report['queue']);
        self::assertGreaterThanOrEqual(1, $report['incidents']['openIncidents']);
        self::assertGreaterThanOrEqual(1, $report['incidents']['failingFeeds']);
        self::assertStringNotContainsString($feed->getCanonicalUrl(), $tester->getDisplay());
    }

    public function testTheStatusCommandRequestsAttentionWhenTheWorkerIsSilentOrMessagesFailed(): void
    {
        $this->db->executeStatement("INSERT INTO messenger_worker_heartbeat (name, last_seen_at) VALUES ('async', NOW()) ON DUPLICATE KEY UPDATE last_seen_at = NOW()");
        $this->db->executeStatement('DELETE FROM messenger_messages');
        $tester = new CommandTester((new Application(self::$kernel))->find('app:status'));

        $this->db->executeStatement("INSERT INTO messenger_messages (body, headers, queue_name, created_at, available_at) VALUES ('{}', '{}', 'failed', NOW(), NOW())");
        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('1 failed', $tester->getDisplay());
        self::assertStringContainsString('Status: attention', $tester->getDisplay());

        $this->db->executeStatement('DELETE FROM messenger_messages');
        $this->db->executeStatement("UPDATE messenger_worker_heartbeat SET last_seen_at = '2000-01-01 00:00:00' WHERE name = 'async'");
        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('Worker: silent', $tester->getDisplay());
    }
}
