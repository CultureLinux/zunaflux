<?php

declare(strict_types=1);

namespace App\Application\Notification;

use App\Entity\PushExpiredNotice;
use App\Entity\PushSubscription;
use Doctrine\ORM\EntityManagerInterface;
use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Nyholm\Psr7\Factory\Psr17Factory;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Psr18Client;

final readonly class PushDelivery
{
    public function __construct(private EntityManagerInterface $em, private string $pushPrivateFile, private string $pushSubject) {}

    public function configured(): bool
    {
        return is_file($this->pushPrivateFile) && is_readable($this->pushPrivateFile) && '' !== $this->pushSubject;
    }

    public function send(PushSubscription $subscription, string $payload): MessageSentReport
    {
        if (!$this->configured() || !PushEndpoint::isAllowed($subscription->getEndpoint())) {
            throw new \RuntimeException('Push unavailable');
        }
        $factory = new Psr17Factory();
        $client = new Psr18Client(HttpClient::create(['max_redirects' => 0, 'timeout' => 10]), $factory, $factory);
        $push = new WebPush(['VAPID' => ['subject' => $this->pushSubject, 'pemFile' => $this->pushPrivateFile]], [], $client, $factory, $factory);
        $recipient = Subscription::create(['endpoint' => $subscription->getEndpoint(), 'keys' => ['p256dh' => $subscription->getPublicKey(), 'auth' => $subscription->getAuthSecret()], 'contentEncoding' => 'aes128gcm']);

        return $push->sendOneNotification($recipient, $payload);
    }

    public function record(PushSubscription $subscription, MessageSentReport $report): void
    {
        if ($report->isSubscriptionExpired()) {
            $this->em->persist(new PushExpiredNotice($subscription));
            $this->em->remove($subscription);
        } else {
            $subscription->recordResult($report->isSuccess() ? 'accepted' : 'failed');
        }
    }
}
