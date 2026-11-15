<?php

declare(strict_types=1);

namespace App\Command;

use App\Application\Notification\SendNotificationDigests;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Uid\Uuid;

#[AsCommand(name: 'app:notifications:send', description: 'Sends due personal notification digests.')]
final class SendNotificationsCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly SendNotificationDigests $sender) { parent::__construct(); }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $users = $this->em->getConnection()->fetchFirstColumn('SELECT DISTINCT s.user_id FROM subscription s JOIN notification_pending p ON p.subscription_id = s.id');
        $sent = 0;
        foreach ($users as $user) {
            $sent += $this->sender->send(Uuid::fromBinary($user), new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
            $this->em->clear();
        }
        $output->writeln(sprintf('%d notification(s) accepted.', $sent));
        return Command::SUCCESS;
    }
}
