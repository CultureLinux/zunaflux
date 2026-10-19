<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Feed;
use App\Message\CollectFeedMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(name: 'app:feeds:collect', description: 'Queues collection for every known feed.')]
final class CollectFeedsCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $entityManager, private readonly MessageBusInterface $messageBus) { parent::__construct(); }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $count = 0; foreach ($this->entityManager->getRepository(Feed::class)->findAll() as $feed) { $this->messageBus->dispatch(new CollectFeedMessage($feed->getId()->toRfc4122())); ++$count; }
        $output->writeln(sprintf('%d feed(s) queued.', $count)); return Command::SUCCESS;
    }
}
