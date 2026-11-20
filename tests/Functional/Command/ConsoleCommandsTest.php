<?php

declare(strict_types=1);

namespace App\Tests\Functional\Command;

use App\Application\Maintenance\ArticleRetention;
use App\Command\CollectFeedsCommand;
use App\Command\PurgeArticlesCommand;
use App\Entity\User;
use App\Message\CollectFeedMessage;
use App\Tests\Support\FrozenClock;
use App\Tests\Support\RollbackKernelTestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class ConsoleCommandsTest extends RollbackKernelTestCase
{
    public function testOnlyDueFeedsWithAnActiveSubscriberAreQueued(): void
    {
        $reader = $this->user();
        $due = $this->feed('due');
        $nobody = $this->feed('nobody');
        $disabledOnly = $this->feed('disabled');
        $later = $this->feed('later');
        $this->subscribe($reader, $due);
        $this->subscribe($reader, $disabledOnly)->disable();
        $this->subscribe($reader, $later);
        $this->em->flush();
        $this->db->executeStatement('UPDATE feed SET next_collection_at = ? WHERE id = ?', ['2999-01-01 00:00:00', $later->getId()->toBinary()]);
        $bus = new class implements MessageBusInterface {
            /** @var list<object> */
            public array $messages = [];

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $this->messages[] = $message;

                return new Envelope($message);
            }
        };
        $tester = new CommandTester(new CollectFeedsCommand($this->em, $bus));

        self::assertSame(Command::SUCCESS, $tester->execute([]));

        $queued = array_map(static fn (CollectFeedMessage $message): string => $message->feedId, $bus->messages);
        self::assertContains((string) $due->getId(), $queued);
        foreach ([$nobody, $disabledOnly, $later] as $skipped) {
            self::assertNotContains((string) $skipped->getId(), $queued);
        }
        self::assertSame(count($queued), count(array_unique($queued)));
        self::assertStringContainsString(sprintf('%d feed(s) queued.', count($queued)), $tester->getDisplay());
    }

    public function testThePurgeCommandDeletesExpiredArticlesAndValidatesItsOptions(): void
    {
        $feed = $this->feed();
        $expired = $this->article($feed, 'expired');
        $recent = $this->article($feed, 'recent');
        $this->em->flush();
        $this->db->executeStatement('UPDATE article SET collected_at = ? WHERE id = ?', ['2000-01-01 00:00:00', $expired->getId()->toBinary()]);
        $this->db->executeStatement('UPDATE article SET collected_at = ? WHERE id = ?', ['2026-11-01 00:00:00', $recent->getId()->toBinary()]);
        $retention = new ArticleRetention($this->db, 12, new FrozenClock(new \DateTimeImmutable('2026-11-15 12:00:00 UTC')));
        $tester = new CommandTester(new PurgeArticlesCommand($retention, new NullLogger()));

        self::assertSame(Command::INVALID, $tester->execute(['--max-seconds' => 'abc']));
        self::assertSame(Command::INVALID, $tester->execute(['--batch-size' => '5000']));
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM article WHERE id = ?', [$expired->getId()->toBinary()]));

        self::assertSame(Command::SUCCESS, $tester->execute(['--max-seconds' => '5', '--batch-size' => '50']));

        self::assertMatchesRegularExpression('/Articles supprimés : \d+/', $tester->getDisplay());
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM article WHERE id = ?', [$expired->getId()->toBinary()]));
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM article WHERE id = ?', [$recent->getId()->toBinary()]));
    }

    public function testTheUserCreationCommandHashesThePasswordAndGrantsTheAdminRoleOnRequest(): void
    {
        $application = new Application(self::$kernel);
        $tester = new CommandTester($application->find('app:user:create'));
        $email = 'cli-'.bin2hex(random_bytes(6)).'@example.test';
        $adminEmail = 'cli-admin-'.bin2hex(random_bytes(6)).'@example.test';

        self::assertSame(Command::SUCCESS, $tester->execute(['email' => $email, 'password' => 'a-secure-password']));
        self::assertSame(Command::SUCCESS, $tester->execute(['email' => $adminEmail, 'password' => 'a-secure-password', '--admin' => true]));

        $this->em->clear();
        $plain = $this->em->getRepository(User::class)->findOneBy(['emailNormalized' => $email]);
        $admin = $this->em->getRepository(User::class)->findOneBy(['emailNormalized' => $adminEmail]);
        self::assertInstanceOf(User::class, $plain);
        self::assertInstanceOf(User::class, $admin);
        self::assertFalse($plain->isAdmin());
        self::assertTrue($admin->isAdmin());
        self::assertNotSame('a-secure-password', $plain->getPassword());
        self::assertTrue(self::getContainer()->get('security.user_password_hasher')->isPasswordValid($plain, 'a-secure-password'));
        self::assertStringContainsString('Account created for '.$adminEmail.'.', $tester->getDisplay());
    }

    public function testTheNotificationCommandsRunCleanlyWithNothingPending(): void
    {
        $this->db->executeStatement('DELETE FROM notification_outbox');
        $this->db->executeStatement('DELETE FROM notification_pending');
        $application = new Application(self::$kernel);

        $relay = new CommandTester($application->find('app:notifications:relay'));
        self::assertSame(Command::SUCCESS, $relay->execute([]));
        self::assertStringContainsString('0 notification event(s) queued.', $relay->getDisplay());

        $send = new CommandTester($application->find('app:notifications:send'));
        self::assertSame(Command::SUCCESS, $send->execute([]));
        self::assertStringContainsString('0 notification(s) accepted.', $send->getDisplay());
    }
}
