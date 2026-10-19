<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Category;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class SubscriptionTest extends TestCase
{
    public function testItRejectsACategoryOwnedByAnotherUser(): void
    {
        $subscriber = new User('reader@example.test');
        $otherUser = new User('other@example.test');
        $category = new Category($otherUser, 'Tech');
        $subscription = new Subscription($subscriber, new Feed('https://example.test/feed.xml'));

        $this->expectException(\DomainException::class);

        $subscription->assignCategory($category);
    }

    public function testItCanBeDisabledAndRenamedByItsOwner(): void
    {
        $user = new User('reader@example.test');
        $subscription = new Subscription($user, new Feed('https://example.test/feed.xml'));

        $subscription->rename('  Journal personnel  ');
        $subscription->disable();

        self::assertSame('Journal personnel', $subscription->getDisplayTitle());
        self::assertFalse($subscription->isEnabled());
    }
}
