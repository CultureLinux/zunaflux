<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Feed;

use App\Application\Notification\PushEndpoint;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PushEndpointTest extends TestCase
{
    #[DataProvider('endpoints')]
    public function testOnlyTrustedHttpsPushEndpointsAreAllowed(string $endpoint, bool $allowed): void
    {
        self::assertSame($allowed, PushEndpoint::isAllowed($endpoint));
    }

    public static function endpoints(): iterable
    {
        yield ['https://fcm.googleapis.com/fcm/send/token', true];
        yield ['https://updates.push.services.mozilla.com/wpush/v2/token', true];
        yield ['https://fcm.googleapis.com.evil.test/token', false];
        yield ['https://fcm.googleapis.com@127.0.0.1/token', false];
        yield ['https://127.0.0.1/token', false];
        yield ['http://fcm.googleapis.com/token', false];
        yield ['https://fcm.googleapis.com:8443/token', false];
    }
}
