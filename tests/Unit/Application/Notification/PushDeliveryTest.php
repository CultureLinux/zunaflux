<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Notification;

use App\Application\Notification\PushDelivery;
use App\Entity\PushExpiredNotice;
use App\Entity\PushSubscription;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Minishlink\WebPush\MessageSentReport;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class PushDeliveryTest extends TestCase
{
    public function testExpiredSubscriptionIsRemovedAndAnAnonymousNoticeIsKept(): void
    {
        $subscription = new PushSubscription(new User('owner@example.test'), 'https://fcm.googleapis.com/fcm/send/secret', 'key', 'auth');
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('persist')->with(self::isInstanceOf(PushExpiredNotice::class));
        $em->expects(self::once())->method('remove')->with($subscription);
        $report = new MessageSentReport(new Request('POST', 'https://fcm.googleapis.com/fcm/send/secret'), new Response(410), false);
        (new PushDelivery($em, '', ''))->record($subscription, $report);
    }

    public function testAcceptedAndFailedResultsAreRecordedSeparately(): void
    {
        $subscription = new PushSubscription(new User('owner@example.test'), 'https://fcm.googleapis.com/fcm/send/secret', 'key', 'auth');
        $delivery = new PushDelivery($this->createMock(EntityManagerInterface::class), '', '');
        $request = new Request('POST', 'https://fcm.googleapis.com/fcm/send/secret');
        $delivery->record($subscription, new MessageSentReport($request, new Response(201)));
        self::assertSame('accepted', $subscription->getLastResult());
        self::assertNotNull($subscription->getLastResultAt());
        $delivery->record($subscription, new MessageSentReport($request, new Response(503), false));
        self::assertSame('failed', $subscription->getLastResult());
    }

    public function testTwoDevicesKeepIndependentDeliveryResults(): void
    {
        $user = new User('owner@example.test');
        $first = new PushSubscription($user, 'https://fcm.googleapis.com/fcm/send/first', 'key', 'auth');
        $second = new PushSubscription($user, 'https://fcm.googleapis.com/fcm/send/second', 'key', 'auth');
        $delivery = new PushDelivery($this->createMock(EntityManagerInterface::class), '', '');
        $delivery->record($first, new MessageSentReport(new Request('POST', $first->getEndpoint()), new Response(201)));
        $delivery->record($second, new MessageSentReport(new Request('POST', $second->getEndpoint()), new Response(503), false));
        self::assertSame('accepted', $first->getLastResult());
        self::assertSame('failed', $second->getLastResult());
    }
}
