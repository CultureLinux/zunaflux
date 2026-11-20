<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Application\Feed\CollectFeed;
use App\Application\Feed\FeedCollectionInProgress;
use App\Application\Notification\IncidentMessage;
use App\Application\Notification\RelayNotificationOutbox;
use App\Entity\Feed;
use App\Entity\FeedIncident;
use App\Entity\FeedIncidentOccurrence;
use App\Entity\PushSubscription;
use App\Entity\Subscription;
use App\Message\CollectFeedMessage;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler]
final readonly class CollectFeedHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private CollectFeed $collectFeed,
        private RelayNotificationOutbox $outboxRelay,
        private LoggerInterface $logger,
        private IncidentMessage $incidentMessage = new IncidentMessage(),
    ) {
    }

    /** Messenger entry point: failures are recorded on the feed and never redelivered. */
    public function __invoke(CollectFeedMessage $message): void
    {
        $this->process($message, false);
    }

    /** Synchronous entry point for callers that must know whether the collection failed. */
    public function collectOrFail(CollectFeedMessage $message): void
    {
        $this->process($message, true);
    }

    private function process(CollectFeedMessage $message, bool $rethrow): void
    {
        $feed = $this->entityManager->getRepository(Feed::class)->find(Uuid::fromString($message->feedId));
        if (!$feed instanceof Feed) {
            return;
        }
        $this->syncEntry($message, 'running');
        try {
            $new = $this->collectFeed->collect($feed);
        } catch (FeedCollectionInProgress $error) {
            // Another worker already collects this feed: nothing to record, no retry.
            $this->syncEntry($message, 'no_new_articles', 0, 'Collecte déjà en cours.');
            if ($rethrow) {
                throw $error;
            }

            return;
        } catch (\Throwable $error) {
            if (!$this->entityManager->isOpen()) {
                // A failed atomic write closes the manager. Never flush its stale
                // article/validator state while trying to record a feed incident.
                $this->syncEntry($message, 'error', 0, 'Échec de l’enregistrement de la collecte.');
                throw $error;
            }
            $feed->recordFailure($error);
            $incident = $this->entityManager->getRepository(FeedIncident::class)->findOneBy(['feed' => $feed]);
            if ($incident instanceof FeedIncident) {
                $incident->report($error);
            } else {
                $incident = new FeedIncident($feed, $error);
                $this->entityManager->persist($incident);
            }
            $this->entityManager->persist(new FeedIncidentOccurrence($incident, $error));
            $this->entityManager->flush();
            $this->syncEntry($message, 'error', 0, $this->incidentMessage->describe($error->getMessage()));
            // The failure and its exponential backoff are recorded above. Rethrowing
            // to Messenger would redeliver immediately and multiply failureCount.
            if ($rethrow) {
                throw $error;
            }

            return;
        }
        $incident = $this->entityManager->getRepository(FeedIncident::class)->findOneBy(['feed' => $feed]);
        if ($incident instanceof FeedIncident) {
            $now = new \DateTimeImmutable();
            if ($incident->shouldBeClearedAt($now)) {
                $this->entityManager->remove($incident);
            } else {
                $incident->markRecoveredAt($now);
            }
            $this->entityManager->flush();
        }
        $this->syncEntry($message, $new > 0 ? 'collected' : 'no_new_articles', $new);
        if ($new > 0) {
            $this->relayNotification($feed, $new);
        } else {
            $this->logNotQueued($feed, 0, 'no_new_articles');
        }
    }

    private function syncEntry(CollectFeedMessage $message, string $status, int $articles = 0, ?string $detail = null): void
    {
        if (null === $message->syncRunId) {
            return;
        }
        try {
            $this
                ->entityManager
                ->getConnection()
                ->executeStatement(
                    'UPDATE feed_sync_run_entry SET status = ?, new_articles = ?, detail = ?, finished_at = CASE WHEN ? '
.'IN (\'collected\',\'no_new_articles\',\'error\') THEN NOW() ELSE NULL END WHERE run_id = ? AND '
.'feed_id = ?',
                    [$status, $articles, $detail, $status, Uuid::fromString($message->syncRunId)->toBinary(), Uuid::fromString($message->feedId)->toBinary()],
                );
        } catch (\Throwable $error) {
            // Sync-run bookkeeping is best effort and must never fail a collection.
            $this
                ->logger
                ->warning(
                    'Impossible de mettre à jour le suivi de synchronisation.',
                    ['feed_id' => $message->feedId, 'status' => $status, 'exception' => $error::class],
                );
        }
    }

    private function relayNotification(Feed $feed, int $count): void
    {
        try {
            $this->outboxRelay->dispatchPending(100, $feed->getId());
        } catch (\Throwable) {
            // The collection and its event are already committed. The scheduler
            // or relay command will retry the durable event with the same ID.
            $this->logNotQueued($feed, $count, 'outbox_retry_pending');
        }
    }

    private function logNotQueued(Feed $feed, int $count, string $status, string $kind = 'articles'): void
    {
        try {
            $traces = $this
                ->entityManager
                ->createQueryBuilder()
                ->select('u.emailNormalized AS userEmail, d.id AS deviceId, d.deviceLabel AS deviceLabel')
                ->from(Subscription::class, 's')
                ->innerJoin('s.user', 'u')
                ->leftJoin(PushSubscription::class, 'd', 'WITH', 'd.user = u')
                ->where('s.feed = :feed AND s.enabled = true')
                ->setParameter('feed', $feed->getId(), 'uuid')
                ->getQuery()
                ->getArrayResult();
            foreach ($traces as $trace) {
                $this
                    ->logger
                    ->info(
                        'Notification de collecte non mise en file.',
                        [
                            'feed_id' => (string) $feed->getId(),
                            'kind' => $kind,
                            'article_count' => $count,
                            'user_email' => $trace['userEmail'],
                            'status' => $status,
                            'device_id' => null === $trace['deviceId'] ? null : (string) $trace['deviceId'],
                            'device_label' => $trace['deviceLabel'],
                        ],
                    );
            }
        } catch (\Throwable) {
            $this
                ->logger
                ->error(
                    'Impossible de tracer les notifications non mises en file.',
                    ['feed_id' => (string) $feed->getId(), 'kind' => $kind, 'article_count' => $count, 'status' => 'trace_error'],
                );
        }
    }
}
