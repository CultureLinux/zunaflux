<?php

declare(strict_types=1);

namespace App\Tests\Functional\Application;

use App\EventSubscriber\MessengerWorkerHeartbeatSubscriber;
use App\Message\DiscoverFeedMessage;
use App\MessageHandler\DiscoverFeedHandler;
use App\Tests\Support\RollbackKernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class WorkerAndDiscoveryTest extends RollbackKernelTestCase
{
    public function testTheHeartbeatIsRecordedAndThrottled(): void
    {
        $this->db->executeStatement("DELETE FROM messenger_worker_heartbeat WHERE name = 'async'");
        $subscriber = new MessengerWorkerHeartbeatSubscriber($this->db);

        $subscriber->record();
        $first = $this->db->fetchOne("SELECT last_seen_at FROM messenger_worker_heartbeat WHERE name = 'async'");
        $this->db->executeStatement("UPDATE messenger_worker_heartbeat SET last_seen_at = '2000-01-01 00:00:00' WHERE name = 'async'");
        $subscriber->record();

        self::assertNotFalse($first);
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM messenger_worker_heartbeat WHERE name = 'async'"));
        // The second call falls within the throttle window: the stale value is not refreshed.
        self::assertSame('2000-01-01 00:00:00', $this->db->fetchOne("SELECT last_seen_at FROM messenger_worker_heartbeat WHERE name = 'async'"));
        self::assertSame([\Symfony\Component\Messenger\Event\WorkerRunningEvent::class => 'record'], MessengerWorkerHeartbeatSubscriber::getSubscribedEvents());
    }

    public function testDiscoveryIgnoresAMessageWhoseCacheEntryIsGone(): void
    {
        $cache = new ArrayAdapter();
        $handler = $this->handler($cache);

        $handler(new DiscoverFeedMessage('missing', 'https://example.test/'));

        self::assertFalse($cache->getItem('missing')->isHit());
    }

    public function testDiscoveryStoresAnErrorForAnUnsafeAddress(): void
    {
        $cache = new ArrayAdapter();
        $item = $cache->getItem('run-1');
        $cache->save($item->set(['status' => 'pending', 'feeds' => []]));

        // Loopback is refused before any request: the handler must record the failure, not throw.
        $this->handler($cache)(new DiscoverFeedMessage('run-1', 'http://127.0.0.1/feed'));

        self::assertSame(['status' => 'error', 'feeds' => []], $cache->getItem('run-1')->get());
    }

    private function handler(ArrayAdapter $cache): DiscoverFeedHandler
    {
        return new DiscoverFeedHandler(
            self::getContainer()->get(\App\Domain\Feed\PageFetcherInterface::class),
            self::getContainer()->get(\App\Domain\Feed\FeedDiscovery::class),
            $cache,
        );
    }
}
