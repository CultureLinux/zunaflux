<?php

declare(strict_types=1);

namespace App\Command;

use App\Application\Administration\AdminStatistics;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** Container healthcheck of the Messenger worker: succeeds only while its heartbeat is recent. */
#[AsCommand(name: 'app:worker:healthcheck', description: 'Fails when the Messenger worker has not reported in the last 90 seconds.')]
final class WorkerHealthcheckCommand extends Command
{
    public function __construct(private readonly AdminStatistics $statistics)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $active = $this->statistics->worker()['workerActive'];
        } catch (\Throwable) {
            $output->writeln('database unavailable');

            return Command::FAILURE;
        }
        $output->writeln($active ? 'worker alive' : 'worker silent');

        return $active ? Command::SUCCESS : Command::FAILURE;
    }
}
