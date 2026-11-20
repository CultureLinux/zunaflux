<?php

declare(strict_types=1);

namespace App\Application\Notification;

use App\Entity\PushSubscription;
use App\Entity\User;
use App\Entity\UserPreference;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class SendNotificationDigests
{
    public function __construct(
        private EntityManagerInterface $em,
        private DigestQueue $queue,
        private PushSender $delivery,
        private PushMessage $messages,
        private NotificationCounter $counter,
        private NotificationDiagnostics $diagnostics,
        private LoggerInterface $logger,
    ) {
    }

    public function send(Uuid $userId, \DateTimeImmutable $now): int
    {
        if (!$this->queue->lock($userId)) {
            return 0;
        }
        try {
            $user = $this->em->find(User::class, $userId);
            if (!$user instanceof User || !$user->isActive()) {
                return 0;
            }
            $preference = $this->em->getRepository(UserPreference::class)->findOneBy(['user' => $user]);
            if ($preference instanceof UserPreference) {
                $this->em->refresh($preference);
            } else {
                $preference = new UserPreference($user);
            }
            if (!$preference->allowsPushAt($now)) {
                return 0;
            }
            $sent = 0;
            foreach ($this->em->getRepository(PushSubscription::class)->findBy(['user' => $user]) as $device) {
                $this->em->refresh($device);
                $pending = $this->queue->pending($userId, $device->getDigestCursor());
                if (0 === $pending['count'] || null === $pending['first']) {
                    continue;
                }
                $after = $device->getDigestSentAt() ?? $pending['first'];
                // An empty fixed slot must not trigger a late notification for articles arriving afterwards.
                if ('fixed' === $preference->getNotificationMode() && $pending['first'] > $after) {
                    $after = $pending['first'];
                }
                if (!$preference->isNotificationDue($after, $now)) {
                    continue;
                }
                $context = [
                    'user_email' => $user->getUserIdentifier(),
                    'device_id' => (string) $device->getId(),
                    'device_label' => $device->getDeviceLabel(),
                    'article_count' => $pending['count'],
                ];
                if (!$this->delivery->configured()) {
                    $this->logger->error('Configuration Web Push indisponible.', $context + ['status' => 'configuration_error']);
                    continue;
                }
                if (!PushEndpoint::isAllowed($device->getEndpoint())) {
                    $device->recordResult('failed');
                    $this->em->flush();
                    $this->logger->warning('Point de terminaison non autorisé.', $context + ['status' => 'unsupported_endpoint']);
                    continue;
                }
                $payload = json_encode(
                    ['body' => $this->messages->articles($pending['count'], $preference->getLocale()), 'tag' => 'zunaflux'],
                    JSON_THROW_ON_ERROR,
                );
                try {
                    $report = $this->delivery->send($device, $payload);
                } catch (\Throwable $error) {
                    $device->recordResult('failed');
                    $this->em->flush();
                    $this->diagnostics->exception('récapitulatif', 'envoi', $error);
                    $this->logger->warning('Échec du récapitulatif.', $context + ['status' => 'delivery_error']);
                    continue;
                }
                $this->delivery->record($device, $report);
                if ($report->isSuccess()) {
                    $device->recordDigest($pending['cursor'], $now->setTimezone(new \DateTimeZone('UTC')));
                    ++$sent;
                }
                // Persist each accepted device before processing the next one.
                $this->em->flush();
                if ($report->isSuccess()) {
                    $this->counter->recordAccepted();
                }
                $status = $report->isSuccess() ? 'sent' : ($report->isSubscriptionExpired() ? 'expired_subscription' : 'delivery_failed');
                $this->logger->info('Récapitulatif traité.', $context + ['status' => $status]);
            }
            $this->queue->prune($userId, $now);

            return $sent;
        } finally {
            $this->queue->unlock($userId);
        }
    }
}
