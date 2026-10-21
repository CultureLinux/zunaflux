<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Notification;

use App\Application\Notification\IncidentMessage;
use PHPUnit\Framework\TestCase;

final class IncidentMessageTest extends TestCase
{
    public function testItExplainsCommonFailuresAndPreservesAnUnknownFallback(): void
    {
        $formatter = new IncidentMessage();
        self::assertSame('Le site a mis trop de temps à répondre.', $formatter->describe('Feed request failed: Operation timed out after 15000 milliseconds'));
        self::assertSame('Le flux est introuvable sur le site (HTTP 404).', $formatter->describe('Feed request returned HTTP 404.'));
        self::assertSame('Le site limite les requêtes (HTTP 429).', $formatter->describe('Feed request returned HTTP 429.'));
        self::assertSame('Le serveur du site rencontre une erreur (HTTP 503).', $formatter->describe('Feed request returned HTTP 503.'));
        self::assertSame('Le nom de domaine du site ne peut pas être résolu.', $formatter->describe('The URL host cannot be resolved.'));
        self::assertSame('Une erreur est survenue pendant la collecte du flux.', $formatter->describe('erreur imprévue'));
    }
}
