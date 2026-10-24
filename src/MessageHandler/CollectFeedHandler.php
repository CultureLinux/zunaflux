<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Application\Feed\CollectFeed;
use App\Entity\Feed;
use App\Entity\FeedIncident;
use App\Entity\FeedIncidentOccurrence;
use App\Entity\PushSubscription;
use App\Entity\Subscription;
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
        $this->syncEntry($message, 'running');
        try {
            $new = $this->collectFeed->collect($feed);
        } catch (\Throwable $error) {
            $firstFailure = 0 === $feed->getFailureCount();
            $feed->recordFailure($error);
            $incident = $this->entityManager->getRepository(FeedIncident::class)->findOneBy(['feed' => $feed]);
            if ($incident instanceof FeedIncident) $incident->report($error); else { $incident = new FeedIncident($feed, $error); $this->entityManager->persist($incident); }
            $this->entityManager->persist(new FeedIncidentOccurrence($incident, $error));
            $this->entityManager->flush();
            if ($firstFailure) $this->queueNotification(new NotifyFeedMessage($message->feedId, 'error'));
            $this->syncEntry($message, 'error', 0, (new \App\Application\Notification\IncidentMessage())->describe($error->getMessage())); throw $error;
        }
        $incident = $this->entityManager->getRepository(FeedIncident::class)->findOneBy(['feed' => $feed]);
        if ($incident instanceof FeedIncident) {
            $now = new \DateTimeImmutable();
            if ($incident->shouldBeClearedAt($now)) $this->entityManager->remove($incident);
            else $incident->markRecoveredAt($now);
            $this->entityManager->flush();
        }
        $this->syncEntry($message, $new > 0 ? 'collected' : 'no_new_articles', $new);
        if ($new > 0) $this->queueNotification(new NotifyFeedMessage($message->feedId, 'articles', $new));
        else $this->logNotQueued($feed, 0, 'no_new_articles');
    }
    private function syncEntry(CollectFeedMessage $message, string $status, int $articles = 0, ?string $detail = null): void { if (null === $message->syncRunId) return; try { $this->entityManager->getConnection()->executeStatement('UPDATE feed_sync_run_entry SET status = ?, new_articles = ?, detail = ?, finished_at = CASE WHEN ? IN (\'collected\',\'no_new_articles\',\'error\') THEN NOW() ELSE NULL END WHERE run_id = ? AND feed_id = ?', [$status, $articles, $detail, $status, \Symfony\Component\Uid\Uuid::fromString($message->syncRunId)->toBinary(), \Symfony\Component\Uid\Uuid::fromString($message->feedId)->toBinary()]); } catch (\Throwable) {} }

    private function queueNotification(NotifyFeedMessage $message): void
    {
        try { $this->bus->dispatch($message); } catch (\Throwable) {
            $this->logger->error('Impossible de placer une notification de collecte en file.', ['feed_id' => $message->feedId, 'kind' => $message->kind, 'article_count' => $message->count, 'status' => 'queue_error']);
            $feed = $this->entityManager->getRepository(Feed::class)->find(Uuid::fromString($message->feedId));
            if ($feed instanceof Feed) $this->logNotQueued($feed, $message->count, 'queue_error', $message->kind);
        }
    }

    private function logNotQueued(Feed $feed, int $count, string $status, string $kind = 'articles'): void
    {
        try {
            foreach ($this->entityManager->getRepository(Subscription::class)->findBy(['feed' => $feed, 'enabled' => true]) as $feedSubscription) {
                $user = $feedSubscription->getUser();
                $context = ['feed_id' => (string) $feed->getId(), 'kind' => $kind, 'article_count' => $count, 'user_email' => $user->getUserIdentifier(), 'status' => $status];
                $devices = $this->entityManager->getRepository(PushSubscription::class)->findBy(['user' => $user]);
                if ([] === $devices) $this->logger->info('Notification de collecte non mise en file.', $context + ['device_id' => null, 'device_label' => null]);
                foreach ($devices as $device) {
                    $this->logger->info('Notification de collecte non mise en file.', $context + ['device_id' => (string) $device->getId(), 'device_label' => $device->getDeviceLabel()]);
                }
            }
        } catch (\Throwable) {
            $this->logger->error('Impossible de tracer les notifications non mises en file.', ['feed_id' => (string) $feed->getId(), 'kind' => $kind, 'article_count' => $count, 'status' => 'trace_error']);
        }
    }
}
