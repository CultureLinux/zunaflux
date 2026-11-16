<?php

declare(strict_types=1);

namespace App\Tests\Unit\Message;

use App\Message\CollectFeedMessage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Retry\MultiplierRetryStrategy;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Yaml\Yaml;

final class MessengerRetryTest extends TestCase
{
    public function testConfiguredPolicyStopsAfterFiveRedeliveries(): void
    {
        $configuration = Yaml::parseFile(dirname(__DIR__, 3).'/config/packages/messenger.yaml');
        $policy = $configuration['framework']['messenger']['transports']['async']['retry_strategy'];
        $strategy = new MultiplierRetryStrategy(
            $policy['max_retries'],
            $policy['delay'],
            $policy['multiplier'],
            $policy['max_delay'],
            $policy['jitter'],
        );
        $message = new CollectFeedMessage('00000000-0000-7000-8000-000000000001');
        for ($retry = 0; $retry < 5; ++$retry) {
            self::assertTrue($strategy->isRetryable(new Envelope($message, [new RedeliveryStamp($retry)])));
        }
        self::assertFalse($strategy->isRetryable(new Envelope($message, [new RedeliveryStamp(5)])));
        self::assertFalse($strategy->isRetryable(new Envelope($message, [new RedeliveryStamp(6)])));
    }
}
