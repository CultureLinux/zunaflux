<?php

declare(strict_types=1);

namespace App\Command;

use App\Application\Time\Clock;
use App\Application\Time\SystemClock;
use App\Message\CollectFeedMessage;
use App\Repository\FeedRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(name: 'app:feeds:collect', description: 'Queues collection for every due feed that has an active subscriber.')]
final class CollectFeedsCommand extends Command
{
    public function __construct(private readonly FeedRepository $feeds, private readonly MessageBusInterface $messageBus, private readonly Clock $clock = new SystemClock())
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $count = 0;
        // Only ids are read: no full-table hydration, and feeds nobody follows are skipped.
        foreach ($this->feeds->findDueIds($this->clock->now()) as $id) {
            $this->messageBus->dispatch(new CollectFeedMessage((string) $id));
            ++$count;
        }
        $output->writeln(sprintf('%d feed(s) queued.', $count));

        return Command::SUCCESS;
    }
}
