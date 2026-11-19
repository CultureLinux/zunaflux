<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Message\CollectFeedMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

#[AsCommand(name: 'app:feeds:collect', description: 'Queues collection for every due feed that has an active subscriber.')]
final class CollectFeedsCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $entityManager, private readonly MessageBusInterface $messageBus)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $count = 0;
        // Only ids are read: no full-table hydration, and feeds nobody follows are skipped.
        $query = $this->entityManager->createQueryBuilder()
            ->select('f.id')
            ->from(Feed::class, 'f')
            ->where('f.nextCollectionAt IS NULL OR f.nextCollectionAt <= :now')
            ->andWhere('EXISTS (SELECT 1 FROM '.Subscription::class.' s WHERE s.feed = f AND s.enabled = true)')
            ->setParameter('now', new \DateTimeImmutable(), 'datetime_immutable')
            ->getQuery();

        foreach ($query->toIterable() as $row) {
            $id = $row['id'];
            $this->messageBus->dispatch(new CollectFeedMessage($id instanceof Uuid ? $id->toRfc4122() : (string) $id));
            ++$count;
        }
        $output->writeln(sprintf('%d feed(s) queued.', $count));

        return Command::SUCCESS;
    }
}
