<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Subscription;

use App\Application\Subscription\SubscribeToFeed;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;

final class SubscribeToFeedTest extends TestCase
{
    public function testARefusedSubscriptionCreatesNoOrphanFeed(): void
    {
        $user = new User('limit@example.test');
        $user->setSubscriptionLimit(1);
        $persisted = [];
        $subscribe = $this->service(feedExists: false, subscriptionCount: 1, persisted: $persisted);

        try {
            $subscribe->subscribe($user, 'https://example.test/new');
            self::fail('The limit must refuse the subscription.');
        } catch (\InvalidArgumentException) {
            self::assertSame([], $persisted);
        }
    }

    public function testANewFeedIsCreatedOnceUnderALockThenSubscribed(): void
    {
        $user = new User('new@example.test');
        $persisted = [];
        $subscription = $this->service(feedExists: false, subscriptionCount: 0, persisted: $persisted)->subscribe($user, 'https://example.test/new');

        self::assertInstanceOf(Subscription::class, $subscription);
        self::assertSame([Feed::class, Subscription::class], array_map(static fn (object $entity): string => $entity::class, $persisted));
    }

    /**
     * @param list<object> $persisted
     */
    private function service(bool $feedExists, int $subscriptionCount, array &$persisted): SubscribeToFeed
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturn(1);

        $feeds = $this->createStub(EntityRepository::class);
        $feeds->method('findOneBy')->willReturn($feedExists ? new Feed('https://example.test/new') : null);
        $subscriptions = $this->createStub(EntityRepository::class);
        $subscriptions->method('findOneBy')->willReturn(null);
        $subscriptions->method('count')->willReturn($subscriptionCount);

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);
        $entityManager->method('getRepository')->willReturnCallback(static fn (string $class): EntityRepository => Feed::class === $class ? $feeds : $subscriptions);
        $entityManager->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });

        return new SubscribeToFeed($entityManager);
    }
}
