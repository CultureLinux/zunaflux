<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Application\Feed\CollectFeed;
use App\Entity\Feed;
use App\Message\CollectFeedMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler]
final readonly class CollectFeedHandler
{
    public function __construct(private EntityManagerInterface $entityManager, private CollectFeed $collectFeed) {}
    public function __invoke(CollectFeedMessage $message): void
    {
        $feed = $this->entityManager->getRepository(Feed::class)->find(Uuid::fromString($message->feedId));
        if ($feed instanceof Feed) $this->collectFeed->collect($feed);
    }
}
