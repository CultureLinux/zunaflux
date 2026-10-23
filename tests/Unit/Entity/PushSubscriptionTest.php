<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\PushSubscription;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class PushSubscriptionTest extends TestCase
{
    public function testNewSubscriptionInitializesItsOwnerAndCredentials(): void
    {
        $user = new User('owner@example.test');
        $subscription = new PushSubscription($user, 'https://fcm.googleapis.com/fcm/send/test', 'public-key', 'auth-secret');

        self::assertSame($user, $subscription->getUser());
        self::assertSame('public-key', $subscription->getPublicKey());
        self::assertSame('auth-secret', $subscription->getAuthSecret());
        self::assertNull($subscription->getLastResult());
    }

    public function testChangingOwnerClearsThePreviousDeliveryResult(): void
    {
        $subscription = new PushSubscription(new User('first@example.test'), 'https://fcm.googleapis.com/fcm/send/test', 'public-key', 'auth-secret');
        $subscription->recordResult('accepted');
        $second = new User('second@example.test');
        $subscription->update($second, 'new-key', 'new-secret');

        self::assertSame($second, $subscription->getUser());
        self::assertNull($subscription->getLastResult());
        self::assertNull($subscription->getLastResultAt());
    }
}
