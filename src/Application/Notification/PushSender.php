<?php

declare(strict_types=1);

namespace App\Application\Notification;

use App\Entity\PushSubscription;
use Minishlink\WebPush\MessageSentReport;

interface PushSender
{
    public function configured(): bool;

    public function send(PushSubscription $subscription, string $payload): MessageSentReport;

    public function record(PushSubscription $subscription, MessageSentReport $report): void;
}
