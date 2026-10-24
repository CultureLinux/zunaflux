<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Application\Notification\PushEndpoint;
use App\Application\Notification\NotificationDiagnostics;
use App\Application\Notification\NotificationCounter;
use App\Application\Notification\PushMessage;
use App\Application\Notification\PushDelivery;
use App\Entity\Feed;
use App\Entity\PushSubscription;
use App\Entity\Subscription;
use App\Entity\UserPreference;
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
        private LoggerInterface $logger,
        private NotificationDiagnostics $diagnostics,
        private NotificationCounter $counter,
        private PushMessage $messages,
        private PushDelivery $delivery,
        private string $pushPrivateFile,
        private string $pushSubject,
    ) {}

    public function __invoke(NotifyFeedMessage $message): void
    {
        $configured = is_file($this->pushPrivateFile) && is_readable($this->pushPrivateFile) && '' !== $this->pushSubject;
        $feed = $this->em->getRepository(Feed::class)->find(Uuid::fromString($message->feedId));
        if (!$feed instanceof Feed || !in_array($message->kind, ['articles', 'error'], true)) return;
        foreach ($this->em->getRepository(Subscription::class)->findBy(['feed' => $feed, 'enabled' => true]) as $feedSubscription) {
            $user = $feedSubscription->getUser();
            $preference = $this->em->getRepository(UserPreference::class)->findOneBy(['user' => $user]);
            if ($preference instanceof UserPreference && !$preference->allowsPushAt(new \DateTimeImmutable())) continue;
            if ('articles' === $message->kind && (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM article a INNER JOIN subscription s ON s.feed_id=a.feed_id AND s.user_id=? AND s.enabled=1 LEFT JOIN article_state st ON st.article_id=a.id AND st.user_id=s.user_id WHERE st.id IS NULL OR st.is_read=0', [$user->getId()->toBinary()]) > $message->count) continue;
            $locale = $preference instanceof UserPreference ? $preference->getLocale() : 'fr';
            $body = 'articles' === $message->kind ? $this->messages->articles($message->count, $locale) : $this->messages->error($locale);
            $payload = json_encode(['body' => $body, 'tag' => 'zunaflux'], JSON_THROW_ON_ERROR);
            $context = [
                'feed_id' => $message->feedId,
                'kind' => $message->kind,
                'article_count' => $message->count,
                'user_email' => $user->getUserIdentifier(),
            ];
            $subscriptions = $this->em->getRepository(PushSubscription::class)->findBy(['user' => $user]);
            $this->logger->info('Notification de collecte à traiter.', $context + ['status' => 'detected']);

            if ([] === $subscriptions) {
                $this->logger->warning('Notification Web Push non envoyée : aucun appareil abonné.', $context + ['status' => 'no_push_subscription', 'device_id' => null, 'device_label' => null]);

                continue;
            }

            foreach ($subscriptions as $subscription) {
                $deviceContext = $context + ['device_id' => (string) $subscription->getId(), 'device_label' => $subscription->getDeviceLabel()];
                if (!$configured) {
                    $this->logger->error('Notification Web Push non envoyée : configuration VAPID indisponible.', $deviceContext + ['status' => 'configuration_error']);

                    continue;
                }
                if (!PushEndpoint::isAllowed($subscription->getEndpoint())) {
                    $subscription->recordResult('failed');
                    $this->logger->warning('Notification Web Push non envoyée : point de terminaison non autorisé.', $deviceContext + ['status' => 'unsupported_endpoint']);

                    continue;
                }
                try {
                    $report = $this->delivery->send($subscription, $payload);
                    $this->delivery->record($subscription, $report);
                    if ($report->isSubscriptionExpired()) {
                        $this->logger->warning('Notification Web Push non envoyée : abonnement expiré.', $deviceContext + ['status' => 'expired_subscription']);
                    } elseif (!$report->isSuccess()) {
                        $this->logger->warning('Échec de livraison d’une notification Web Push.', $deviceContext + ['status' => 'delivery_failed']);
                    } else {
                        $this->counter->recordAccepted();
                        $this->logger->info('Notification Web Push envoyée.', $deviceContext + ['status' => 'sent']);
                    }
                } catch (\Throwable $error) {
                    $subscription->recordResult('failed');
                    $this->diagnostics->exception('collecte', 'envoi', $error);
                    $this->logger->warning('Échec de livraison d’une notification Web Push.', $deviceContext + [
                        'status' => 'delivery_error',
                    ]);
                }
            }
        }
        $this->em->flush();
    }
}
