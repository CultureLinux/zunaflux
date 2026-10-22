<?php

declare(strict_types=1);

namespace App\Application\Notification;

use Minishlink\WebPush\MessageSentReport;
use Psr\Log\LoggerInterface;

final readonly class NotificationDiagnostics
{
    public function __construct(private LoggerInterface $logger) {}

    public function subscriptionActivated(): void
    {
        $this->logger->info('Notifications activées.');
    }

    public function subscriptionDeactivated(): void
    {
        $this->logger->info('Notifications désactivées.');
    }

    public function configuration(string $source, bool $fileReadable, bool $subjectSet): void
    {
        $this->logger->warning('Configuration Web Push indisponible.', ['source' => $source, 'private_key_readable' => $fileReadable, 'subject_set' => $subjectSet]);
    }

    public function failure(string $source, MessageSentReport $report): void
    {
        $this->logger->warning('Échec de livraison Web Push.', [
            'source' => $source,
            'http_status' => $report->getResponse()?->getStatusCode(),
            'expired' => $report->isSubscriptionExpired(),
            'cause' => self::classify($report->getReason()),
        ]);
    }

    public function exception(string $source, string $stage, \Throwable $exception): void
    {
        $this->logger->error('Erreur Web Push.', [
            'source' => $source,
            'stage' => $stage,
            'exception_type' => $exception::class,
            'cause' => self::classify($exception->getMessage()),
        ]);
    }

    private static function classify(string $message): string
    {
        $message = strtolower($message);
        return match (true) {
            str_contains($message, 'timed out'), str_contains($message, 'timeout') => 'timeout',
            str_contains($message, 'resolve host'), str_contains($message, 'name resolution'), str_contains($message, 'dns') => 'dns',
            str_contains($message, 'ssl'), str_contains($message, 'certificate'), str_contains($message, 'tls') => 'tls',
            str_contains($message, 'connect'), str_contains($message, 'network') => 'connection',
            str_contains($message, 'key'), str_contains($message, 'pem'), str_contains($message, 'vapid') => 'key_or_vapid',
            default => 'other',
        };
    }
}
