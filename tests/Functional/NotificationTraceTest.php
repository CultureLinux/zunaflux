<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Application\Notification\NotificationCounter;
use App\Application\Notification\NotificationDiagnostics;
use App\Application\Notification\PushDelivery;
use App\Application\Notification\PushMessage;
use App\Entity\Feed;
use App\Entity\PushSubscription;
use App\Entity\Subscription;
use App\Entity\User;
use App\Message\NotifyFeedMessage;
use App\MessageHandler\NotifyFeedHandler;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\AbstractLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class NotificationTraceTest extends KernelTestCase
{
    public function testMissingPushConfigurationIsLoggedForEachUserAndDevice(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $user = new User('notification-trace-'.bin2hex(random_bytes(8)).'@example.test');
        $feed = new Feed('https://example.test/'.bin2hex(random_bytes(8)));
        $device = new PushSubscription($user, 'https://fcm.googleapis.com/fcm/send/secret-token', 'secret-key', 'secret-auth');
        $device->setDeviceLabel('Firefox Android');
        foreach ([$user, $feed, new Subscription($user, $feed), $device] as $entity) $em->persist($entity);
        $em->flush();

        $logger = new class extends AbstractLogger {
            public array $entries = [];
            public function log($level, \Stringable|string $message, array $context = []): void { $this->entries[] = $context; }
        };

        try {
            $handler = new NotifyFeedHandler(
                $em,
                $logger,
                new NotificationDiagnostics($logger),
                new NotificationCounter($em->getConnection(), $logger),
                new PushMessage($container->get(TranslatorInterface::class)),
                new PushDelivery($em, '', ''),
                '',
                '',
            );
            $handler(new NotifyFeedMessage((string) $feed->getId(), 'articles', 3));

            $entries = array_filter($logger->entries, static fn (array $entry): bool => ($entry['status'] ?? null) === 'configuration_error' && ($entry['device_id'] ?? null) === (string) $device->getId());
            self::assertCount(1, $entries);
            $entry = array_values($entries)[0];
            self::assertSame($user->getUserIdentifier(), $entry['user_email']);
            self::assertSame(3, $entry['article_count']);
            self::assertSame('Firefox Android', $entry['device_label']);
            self::assertStringNotContainsString('secret', json_encode($logger->entries, JSON_THROW_ON_ERROR));
        } finally {
            $em->getConnection()->executeStatement('DELETE FROM app_user WHERE id = ?', [$user->getId()->toBinary()]);
            $em->getConnection()->executeStatement('DELETE FROM feed WHERE id = ?', [$feed->getId()->toBinary()]);
        }
    }
}
