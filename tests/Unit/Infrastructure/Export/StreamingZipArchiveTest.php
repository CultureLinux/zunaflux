<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Export;

use App\Infrastructure\Export\StreamingZipArchive;
use PHPUnit\Framework\TestCase;

final class StreamingZipArchiveTest extends TestCase
{
    /** Removing ZIP entry generation or its central directory must make this contract fail. */
    public function testItCreatesAReadableZipArchiveWithoutUsingTemporaryFiles(): void
    {
        $archive = new StreamingZipArchive();
        $bytes = $archive->create(['manifest.json' => "{\"formatVersion\":1}\n"]);

        self::assertStringStartsWith("PK\x03\x04", $bytes);
        self::assertStringContainsString('manifest.json', $bytes);
        self::assertStringContainsString("PK\x05\x06", $bytes);
    }
}
