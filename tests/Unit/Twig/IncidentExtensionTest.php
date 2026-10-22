<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig;

use App\Application\Notification\IncidentMessage;
use App\Twig\IncidentExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Translator;

final class IncidentExtensionTest extends TestCase
{
    public function testIncidentDescriptionTranslatesHttpCode(): void
    {
        $translator = new Translator('de');
        $translator->addLoader('array', new ArrayLoader());
        $translator->addResource('array', ['Le flux est introuvable sur le site (HTTP %code%).' => 'Feed nicht gefunden (HTTP %code%).'], 'de');
        $extension = new IncidentExtension(new IncidentMessage(), $translator);

        self::assertSame('Feed nicht gefunden (HTTP 404).', $extension->describe('Server returned HTTP 404'));
    }
}
