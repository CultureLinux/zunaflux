<?php

declare(strict_types=1);

namespace App\Command;

use App\Application\Administration\AdminStatistics;
use App\Application\Health\HealthCheck;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Operational summary for cron or monitoring: aggregates only, no account data.
 * The exit code is non-zero when the worker is silent, messages have failed or the database is down.
 */
#[AsCommand(name: 'app:status', description: 'Shows worker, queue and feed incident counters (exit code 1 when something needs attention).')]
final class StatusCommand extends Command
{
    public function __construct(private readonly AdminStatistics $statistics, private readonly HealthCheck $health)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Print the counters as JSON.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $queues = $this->health->queues();
            $worker = $this->statistics->worker();
            $incidents = $this->statistics->incidents();
        } catch (\Throwable) {
            $output->writeln($input->getOption('json') ? '{"status":"error","database":"down"}' : 'Database unavailable.');

            return Command::FAILURE;
        }
        $healthy = $worker['workerActive'] && 0 === $queues['failed'];
        $report = [
            'status' => $healthy ? 'ok' : 'attention',
            'worker' => $worker['workerActive'] ? 'alive' : 'silent',
            'queue' => $queues,
            'incidents' => $incidents,
        ];
        if ($input->getOption('json')) {
            $output->writeln(json_encode($report, \JSON_THROW_ON_ERROR));
        } else {
            $output->writeln([
                sprintf('Worker: %s', $report['worker']),
                sprintf('Queue: %d pending, %d failed', $queues['pending'], $queues['failed']),
                sprintf('Feeds: %d failing, %d open incident(s)', $incidents['failingFeeds'], $incidents['openIncidents']),
                sprintf('Status: %s', $report['status']),
            ]);
        }

        return $healthy ? Command::SUCCESS : Command::FAILURE;
    }
}
