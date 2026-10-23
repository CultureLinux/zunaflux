<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Notification;

use App\Application\Notification\PushDeviceLabel;
use PHPUnit\Framework\TestCase;

final class PushDeviceLabelTest extends TestCase
{
    public function testDescribesBrowserAndPlatformWithoutRetainingTheAgent(): void
    {
        self::assertSame('Firefox · Windows', PushDeviceLabel::fromUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) Gecko/20100101 Firefox/130.0'));
        self::assertSame('Chrome · Android', PushDeviceLabel::fromUserAgent('Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 Chrome/130.0 Mobile Safari/537.36'));
        self::assertSame('Navigateur inconnu', PushDeviceLabel::fromUserAgent(''));
    }
}
