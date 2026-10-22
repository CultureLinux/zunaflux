<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Application\Notification\PushEndpoint;
use App\Application\Notification\NotificationDiagnostics;
use App\Application\Notification\NotificationCounter;
use App\Application\Notification\PushMessage;
use App\Entity\Feed;
use App\Entity\PushSubscription;
use App\Entity\Subscription;
use App\Entity\UserPreference;
use App\Message\NotifyFeedMessage;
use Doctrine\ORM\EntityManagerInterface;
use Minishlink\WebPush\Subscription as WebPushSubscription;
use Minishlink\WebPush\WebPush;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Psr18Client;
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
        private string $pushPrivateFile,
        private string $pushSubject,
    ) {}

    public function __invoke(NotifyFeedMessage $message): void
    {
<<<<<<< Updated upstream
        $fileReadable = is_file($this->pushPrivateFile) && is_readable($this->pushPrivateFile);
        if (!$fileReadable || '' === $this->pushSubject) {
            $this->diagnostics->configuration('collecte', $fileReadable, '' !== $this->pushSubject);
=======
        if (!is_file($this->pushPrivateFile) || !is_readable($this->pushPrivateFile) || '' === $this->pushSubject) {
            $this->logger->error('Notification Web Push non envoyée : configuration VAPID indisponible.', [
                'article_count' => $message->count,
                'status' => 'configuration_error',
            ]);

>>>>>>> Stashed changes
            return;
        }
        $feed = $this->em->getRepository(Feed::class)->find(Uuid::fromString($message->feedId));
        if (!$feed instanceof Feed || !in_array($message->kind, ['articles', 'error'], true)) return;
        $factory = new Psr17Factory();
        $client = new Psr18Client(HttpClient::create(['max_redirects' => 0, 'timeout' => 10]), $factory, $factory);
        try {
            $push = new WebPush(['VAPID' => ['subject' => $this->pushSubject, 'pemFile' => $this->pushPrivateFile]], [], $client, $factory, $factory, logger: $this->logger);
        } catch (\Throwable $exception) {
            $this->diagnostics->exception('collecte', 'initialisation', $exception);
            throw $exception;
        }
        foreach ($this->em->getRepository(Subscription::class)->findBy(['feed' => $feed, 'enabled' => true]) as $feedSubscription) {
<<<<<<< Updated upstream
            $preference = $this->em->getRepository(UserPreference::class)->findOneBy(['user' => $feedSubscription->getUser()]);
            $locale = $preference instanceof UserPreference ? $preference->getLocale() : 'fr';
            $body = 'error' === $message->kind ? $this->messages->error($locale) : $this->messages->articles($message->count, $locale);
            $payload = json_encode(['body' => $body, 'tag' => 'feed-'.$message->kind.'-'.$message->feedId], JSON_THROW_ON_ERROR);
            foreach ($this->em->getRepository(PushSubscription::class)->findBy(['user' => $feedSubscription->getUser()]) as $subscription) {
                if (!PushEndpoint::isAllowed($subscription->getEndpoint())) continue;
                try {
                    $recipient = WebPushSubscription::create(['endpoint' => $subscription->getEndpoint(), 'keys' => ['p256dh' => $subscription->getPublicKey(), 'auth' => $subscription->getAuthSecret()], 'contentEncoding' => 'aes128gcm']);
                    $report = $push->sendOneNotification($recipient, $payload);
                    if (!$report->isSuccess()) $this->diagnostics->failure('collecte', $report);
                    else $this->counter->recordAccepted();
                    if ($report->isSubscriptionExpired()) $this->em->remove($subscription);
                } catch (\Throwable $exception) {
                    $this->diagnostics->exception('collecte', 'envoi', $exception);
=======
            $user = $feedSubscription->getUser();
            $context = [
                'article_count' => $message->count,
                'user_email' => $user->getUserIdentifier(),
            ];
            $subscriptions = $this->em->getRepository(PushSubscription::class)->findBy(['user' => $user]);
            $this->logger->info('Nouveaux articles détectés pour un utilisateur.', $context + ['status' => 'detected']);

            if ([] === $subscriptions) {
                $this->logger->warning('Notification Web Push non envoyée : aucun appareil abonné.', $context + ['status' => 'no_push_subscription']);

                continue;
            }

            foreach ($subscriptions as $subscription) {
                if (!PushEndpoint::isAllowed($subscription->getEndpoint())) {
                    $this->logger->warning('Notification Web Push non envoyée : point de terminaison non autorisé.', $context + ['status' => 'unsupported_endpoint']);

                    continue;
                }
                try {
                    $recipient = WebPushSubscription::create(['endpoint' => $subscription->getEndpoint(), 'keys' => ['p256dh' => $subscription->getPublicKey(), 'auth' => $subscription->getAuthSecret()], 'contentEncoding' => 'aes128gcm']);
                    $report = $push->sendOneNotification($recipient, $payload);
                    if ($report->isSubscriptionExpired()) {
                        $this->em->remove($subscription);
                        $this->logger->warning('Notification Web Push non envoyée : abonnement expiré.', $context + ['status' => 'expired_subscription']);
                    } elseif (!$report->isSuccess()) {
                        $this->logger->warning('Échec de livraison d’une notification Web Push.', $context + ['status' => 'delivery_failed']);
                    } else {
                        $this->logger->info('Notification Web Push envoyée.', $context + ['status' => 'sent']);
                    }
                } catch (\Throwable $error) {
                    $this->logger->warning('Échec de livraison d’une notification Web Push.', $context + [
                        'status' => 'delivery_error',
                        'error' => $error->getMessage(),
                    ]);
>>>>>>> Stashed changes
                }
            }
        }
        $this->em->flush();
    }
}
