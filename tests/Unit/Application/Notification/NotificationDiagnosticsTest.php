<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Notification;

use App\Application\Notification\NotificationDiagnostics;
use Minishlink\WebPush\MessageSentReport;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

final class NotificationDiagnosticsTest extends TestCase
{
    public function testSubscriptionChangesAreLoggedAtInfoLevel(): void
    {
        $logger = new class extends AbstractLogger {
            public array $entries = [];
            public function log($level, \Stringable|string $message, array $context = []): void { $this->entries[] = [$level, (string) $message, $context]; }
        };
        $diagnostics = new NotificationDiagnostics($logger);

        $diagnostics->subscriptionActivated();
        $diagnostics->subscriptionDeactivated();

        self::assertSame([LogLevel::INFO, 'Notifications activées.', []], $logger->entries[0]);
        self::assertSame([LogLevel::INFO, 'Notifications désactivées.', []], $logger->entries[1]);
    }

    public function testReportRecordsHttpStatusWithoutExposingEndpointOrResponse(): void
    {
        $logger = new class extends AbstractLogger {
            public array $entries = [];
            public function log($level, \Stringable|string $message, array $context = []): void { $this->entries[] = [$level, (string) $message, $context]; }
        };
        $diagnostics = new NotificationDiagnostics($logger);
        $report = new MessageSentReport(new Request('POST', 'https://updates.push.services.mozilla.com/secret-token'), new Response(403, [], 'secret-response'), false, 'secret-token');

        $diagnostics->failure('test', $report);

        self::assertSame(LogLevel::WARNING, $logger->entries[0][0]);
        self::assertSame(403, $logger->entries[0][2]['http_status']);
        self::assertStringNotContainsString('secret', json_encode($logger->entries, JSON_THROW_ON_ERROR));
    }

    public function testExceptionIsClassifiedWithoutLoggingItsMessage(): void
    {
        $logger = new class extends AbstractLogger {
            public array $entries = [];
            public function log($level, \Stringable|string $message, array $context = []): void { $this->entries[] = [$level, (string) $message, $context]; }
        };
        (new NotificationDiagnostics($logger))->exception('test', 'envoi', new \RuntimeException('Connection timed out for https://example.test/secret-token'));

        self::assertSame('timeout', $logger->entries[0][2]['cause']);
        self::assertStringNotContainsString('secret', json_encode($logger->entries, JSON_THROW_ON_ERROR));
    }
}
