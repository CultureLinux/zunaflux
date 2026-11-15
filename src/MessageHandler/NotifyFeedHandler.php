<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Application\Time\Clock;
use App\Application\Time\SystemClock;
use App\Application\Notification\DigestQueue;
use App\Entity\Feed;
use App\Entity\PushSubscription;
use App\Entity\Subscription;
use App\Message\NotifyFeedMessage;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler]
final readonly class NotifyFeedHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private DigestQueue $queue,
        private LoggerInterface $logger,
        private Clock $clock = new SystemClock(),
    ) {
    }

    public function __invoke(NotifyFeedMessage $message): void
    {
        $feed = $this->em->getRepository(Feed::class)->find(Uuid::fromString($message->feedId));
        if (!$feed instanceof Feed || 'articles' !== $message->kind || $message->count < 1) return;
        // Drain old messages before deployment: they do not carry a stable event identity.
        $event = $message->eventId ?? (string) Uuid::v7();
        foreach ($this->em->getRepository(Subscription::class)->findBy(['feed' => $feed, 'enabled' => true]) as $subscription) {
            $user = $subscription->getUser();
            if (!$user->isActive()) continue;
            if (!$this->queue->lock($user->getId())) throw new \RuntimeException('Notification digest busy.');
            try {
                $context = ['feed_id' => $message->feedId, 'user_email' => $user->getUserIdentifier(), 'article_count' => $message->count];
                if (0 === $this->em->getRepository(PushSubscription::class)->count(['user' => $user])) {
                    $this->logger->info('Aucun appareil pour le récapitulatif.', $context + ['status' => 'no_push_subscription']);
                    continue;
                }
                $this->queue->add($subscription->getId(), $event, $message->count, $this->clock->now()->setTimezone(new \DateTimeZone('UTC')));
                $this->logger->info('Nouveautés conservées pour le récapitulatif.', $context + ['status' => 'digest_pending']);
            } finally {
                $this->queue->unlock($user->getId());
            }
        }
    }
}
