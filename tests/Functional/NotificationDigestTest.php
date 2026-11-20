<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Application\Notification\{DigestQueue, NotificationCounter, NotificationDiagnostics, PushDelivery, PushMessage, PushSender, SendNotificationDigests};
use App\Entity\{Feed, PushSubscription, Subscription, User, UserPreference};
use App\Message\NotifyFeedMessage;
use App\MessageHandler\NotifyFeedHandler;
use App\Tests\Support\FrozenClock;
use Doctrine\ORM\EntityManagerInterface;
use Minishlink\WebPush\MessageSentReport;
use Nyholm\Psr7\{Request, Response};
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class NotificationDigestTest extends KernelTestCase
{
    public function testRetriesAreDeduplicatedAndSourcesAreGroupedPerAccountAndDevice(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $clock = new FrozenClock(new \DateTimeImmutable('2026-11-14 20:00:00 UTC'));
        $queue = new DigestQueue($em->getConnection(), $clock);
        $logger = new NullLogger();
        $user = new User('digest-'.bin2hex(random_bytes(8)).'@example.test');
        $other = new User('digest-other-'.bin2hex(random_bytes(8)).'@example.test');
        $feeds = [new Feed('https://example.test/'.bin2hex(random_bytes(8))), new Feed('https://example.test/'.bin2hex(random_bytes(8)))];
        $first = new PushSubscription($user, 'https://fcm.googleapis.com/fcm/send/'.bin2hex(random_bytes(8)), 'key', 'auth');
        $second = new PushSubscription($user, 'https://fcm.googleapis.com/fcm/send/'.bin2hex(random_bytes(8)), 'key', 'auth');
        $otherDevice = new PushSubscription($other, 'https://fcm.googleapis.com/fcm/send/'.bin2hex(random_bytes(8)), 'key', 'auth');
        $preference = new UserPreference($user);
        $preference->update(['timezone' => 'UTC', 'quiet_start' => '22:00', 'quiet_end' => '08:00']);
        foreach ([$user, $other, ...$feeds, $first, $second, $otherDevice, $preference, new Subscription($user, $feeds[0]), new Subscription($user, $feeds[1]), new Subscription($other, $feeds[0])] as $entity) {
            $em->persist($entity);
        }
        $em->flush();
        try {
            $handler = new NotifyFeedHandler($em, $queue, $logger, $clock);
            $event = new NotifyFeedMessage((string) $feeds[0]->getId(), 'articles', 2);
            $handler($event);
            $handler($event);
            $handler(new NotifyFeedMessage((string) $feeds[1]->getId(), 'articles', 3));
            $pending = $queue->pending($user->getId(), 0);
            self::assertSame(5, $pending['count']);
            self::assertGreaterThan(0, $pending['cursor']);
            self::assertSame(['count' => 0, 'cursor' => 0, 'first' => null], $queue->pending($user->getId(), $pending['cursor']));
            self::assertSame(2, $queue->pending($other->getId(), 0)['count']);
            $attempts = [];
            $delivery = $this->createMock(PushSender::class);
            $delivery->method('configured')->willReturn(true);
            $delivery->method('send')->willReturnCallback(function (PushSubscription $device, string $payload) use (&$attempts, $second): MessageSentReport {
                $id = (string) $device->getId();
                $attempts[$id][] = json_decode($payload, true, 512, JSON_THROW_ON_ERROR)['body'];
                $success = !$device->getId()->equals($second->getId()) || count($attempts[$id]) > 1;

                return new MessageSentReport(new Request('POST', $device->getEndpoint()), new Response($success ? 201 : 503), $success);
            });
            $realDelivery = new PushDelivery($em, '', '');
            $delivery->method('record')->willReturnCallback($realDelivery->record(...));
            $sender = new SendNotificationDigests($em, $queue, $delivery, new PushMessage(self::getContainer()->get(TranslatorInterface::class)), new NotificationCounter($em->getConnection(), $logger, $clock), new NotificationDiagnostics($logger), $logger);
            $sendAt = static function (\DateTimeImmutable $now) use ($clock, $sender, $user): int {
                $clock->set($now);

                return $sender->send($user->getId(), $now);
            };
            self::assertSame(0, $sendAt(new \DateTimeImmutable('2026-11-14 23:00:00 UTC')));
            self::assertSame([], $attempts);
            self::assertSame(1, $sendAt(new \DateTimeImmutable('2026-11-15 08:00:00 UTC')));
            self::assertSame(1, $sendAt(new \DateTimeImmutable('2026-11-15 08:01:00 UTC')));
            self::assertSame(['5 nouveaux articles dans vos sources.'], $attempts[(string) $first->getId()]);
            self::assertCount(2, $attempts[(string) $second->getId()]);
            self::assertArrayNotHasKey((string) $otherDevice->getId(), $attempts);
            self::assertSame(0, $sendAt(new \DateTimeImmutable('2026-11-15 12:00:00 UTC')));
            $handler($event);
            self::assertSame(0, $queue->pending($user->getId(), $first->getDigestCursor())['count']);
            // A fresh device starts at the current cursor, without replaying retained events.
            $newDevice = new PushSubscription($user, 'https://fcm.googleapis.com/fcm/send/'.bin2hex(random_bytes(8)), 'key', 'auth');
            $newDevice->startDigestAt($queue->latestCursor());
            $em->persist($newDevice);
            $em->flush();
            self::assertSame(0, $sendAt(new \DateTimeImmutable('2026-11-15 13:00:00 UTC')));
            self::assertArrayNotHasKey((string) $newDevice->getId(), $attempts);

            $handler(new NotifyFeedMessage((string) $feeds[1]->getId(), 'articles', 4));
            $subscription = $em->getRepository(Subscription::class)->findOneBy(['user' => $user, 'feed' => $feeds[1]]);
            $subscription->disable();
            $em->flush();
            self::assertSame(0, $queue->pending($user->getId(), $first->getDigestCursor())['count']);
            $em->remove($subscription);
            $em->flush();
            $em->persist(new Subscription($user, $feeds[1]));
            $em->flush();
            self::assertSame(0, $queue->pending($user->getId(), $first->getDigestCursor())['count']);

        } finally {
            $em->getConnection()->executeStatement('DELETE FROM app_user WHERE id IN (?, ?)', [$user->getId()->toBinary(), $other->getId()->toBinary()]);
            foreach ($feeds as $feed) {
                $em->getConnection()->executeStatement('DELETE FROM feed WHERE id = ?', [$feed->getId()->toBinary()]);
            }
        }
    }
}
