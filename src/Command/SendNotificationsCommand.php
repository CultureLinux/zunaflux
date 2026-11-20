<?php

declare(strict_types=1);

namespace App\Command;

use App\Application\Time\Clock;
use App\Application\Time\SystemClock;
use App\Application\Notification\RelayNotificationOutbox;
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
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SendNotificationDigests $sender,
        private readonly RelayNotificationOutbox $relay,
        private readonly Clock $clock = new SystemClock(),
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $relayFailed = false;
        try {
            $this->relay->dispatchPending();
        } catch (\Throwable) {
            // Existing digests still deserve their normal delivery attempt.
            $relayFailed = true;
            $output->writeln('<error>Notification relay failed; pending events are retained.</error>');
        }
        $users = $this
            ->em
            ->getConnection()
            ->fetchFirstColumn('SELECT DISTINCT s.user_id FROM subscription s JOIN notification_pending p ON p.subscription_id = s.id');
        $sent = 0;
        foreach ($users as $user) {
            $sent += $this->sender->send(Uuid::fromBinary($user), $this->clock->now()->setTimezone(new \DateTimeZone('UTC')));
            $this->em->clear();
        }
        $output->writeln(sprintf('%d notification(s) accepted.', $sent));

        return $relayFailed ? Command::FAILURE : Command::SUCCESS;
    }
}
