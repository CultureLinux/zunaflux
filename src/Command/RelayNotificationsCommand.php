<?php

declare(strict_types=1);

namespace App\Command;

use App\Application\Notification\RelayNotificationOutbox;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:notifications:relay', description: 'Queues durable collection events without sending Web Push directly.')]
final class RelayNotificationsCommand extends Command
{
    public function __construct(private readonly RelayNotificationOutbox $relay)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $count = $this->relay->dispatchPending();
        } catch (\Throwable) {
            $output->writeln('<error>Notification relay failed; pending events are retained. Check the notification log.</error>');

            return Command::FAILURE;
        }
        $output->writeln(sprintf('%d notification event(s) queued.', $count));

        return Command::SUCCESS;
    }
}
