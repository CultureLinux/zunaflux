<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Application\Feed\CollectFeed;
use App\Entity\Feed;
use App\Entity\FeedIncident;
use App\Message\CollectFeedMessage;
use App\Message\NotifyFeedMessage;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler]
final readonly class CollectFeedHandler
{
    public function __construct(private EntityManagerInterface $entityManager, private CollectFeed $collectFeed, private MessageBusInterface $bus, private LoggerInterface $logger) {}
    public function __invoke(CollectFeedMessage $message): void
    {
        $feed = $this->entityManager->getRepository(Feed::class)->find(Uuid::fromString($message->feedId));
        if (!$feed instanceof Feed) return;
        try {
            $new = $this->collectFeed->collect($feed);
        } catch (\Throwable $error) {
            $firstFailure = 0 === $feed->getFailureCount();
            $feed->recordFailure($error);
            $incident = $this->entityManager->getRepository(FeedIncident::class)->findOneBy(['feed' => $feed]);
            if ($incident instanceof FeedIncident) $incident->report($error); else $this->entityManager->persist(new FeedIncident($feed, $error));
            $this->entityManager->flush();
            if ($firstFailure) $this->queueNotification(new NotifyFeedMessage($message->feedId, 'error'));
            throw $error;
        }
        if ($new > 0) $this->queueNotification(new NotifyFeedMessage($message->feedId, 'articles', $new));
    }

    private function queueNotification(NotifyFeedMessage $message): void
    {
        try { $this->bus->dispatch($message); } catch (\Throwable) { $this->logger->error('Impossible de placer une notification de collecte en file.'); }
    }
}
