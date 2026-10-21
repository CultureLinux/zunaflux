<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Domain\Feed\DiscoveryFetcher;
use App\Domain\Feed\FeedDiscovery;
use App\Message\DiscoverFeedMessage;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class DiscoverFeedHandler
{
    public function __construct(private DiscoveryFetcher $fetcher, private FeedDiscovery $discovery, private CacheItemPoolInterface $cache) {}

    public function __invoke(DiscoverFeedMessage $message): void
    {
        $item = $this->cache->getItem($message->key);
        if (!$item->isHit()) return;
        try {
            $page = $this->fetcher->fetch($message->url);
            $result = ['status' => 'complete', 'feeds' => $this->discovery->find($page['body'], $page['url'])];
        } catch (\Throwable) {
            $result = ['status' => 'error', 'feeds' => []];
        }
        $item->set($result)->expiresAfter(1800);
        $this->cache->save($item);
    }
}
