<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Application\Notification\PushEndpoint;
use App\Entity\Feed;
use App\Entity\PushSubscription;
use App\Entity\Subscription;
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
        private string $pushPrivateFile,
        private string $pushSubject,
    ) {}

    public function __invoke(NotifyFeedMessage $message): void
    {
        if (!is_file($this->pushPrivateFile) || !is_readable($this->pushPrivateFile) || '' === $this->pushSubject) return;
        $feed = $this->em->getRepository(Feed::class)->find(Uuid::fromString($message->feedId));
        if (!$feed instanceof Feed || !in_array($message->kind, ['articles', 'error'], true)) return;
        $factory = new Psr17Factory();
        $client = new Psr18Client(HttpClient::create(['max_redirects' => 0, 'timeout' => 10]), $factory, $factory);
        $push = new WebPush(['VAPID' => ['subject' => $this->pushSubject, 'pemFile' => $this->pushPrivateFile]], [], $client, $factory, $factory, logger: $this->logger);
        $body = 'error' === $message->kind ? 'Une source rencontre une erreur de synchronisation.' : sprintf('%d nouvel(aux) article(s) dans vos sources.', $message->count);
        $payload = json_encode(['body' => $body, 'tag' => 'feed-'.$message->kind.'-'.$message->feedId], JSON_THROW_ON_ERROR);

        foreach ($this->em->getRepository(Subscription::class)->findBy(['feed' => $feed, 'enabled' => true]) as $feedSubscription) {
            foreach ($this->em->getRepository(PushSubscription::class)->findBy(['user' => $feedSubscription->getUser()]) as $subscription) {
                if (!PushEndpoint::isAllowed($subscription->getEndpoint())) continue;
                try {
                    $recipient = WebPushSubscription::create(['endpoint' => $subscription->getEndpoint(), 'keys' => ['p256dh' => $subscription->getPublicKey(), 'auth' => $subscription->getAuthSecret()], 'contentEncoding' => 'aes128gcm']);
                    $report = $push->sendOneNotification($recipient, $payload);
                    if ($report->isSubscriptionExpired()) $this->em->remove($subscription);
                    elseif (!$report->isSuccess()) $this->logger->warning('Échec de livraison d’une notification Web Push.');
                } catch (\Throwable) {
                    $this->logger->warning('Échec de livraison d’une notification Web Push.');
                }
            }
        }
        $this->em->flush();
    }
}
