<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Notification;

use App\Application\Notification\IncidentMessage;
use App\Infrastructure\Http\HttpFailure;
use App\Infrastructure\Http\HttpFetchFailed;
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

    public function testATypedFetchFailureIsDescribedFromItsReasonNotFromItsWording(): void
    {
        $formatter = new IncidentMessage();

        self::assertSame('Le flux dépasse la taille autorisée.', $formatter->describeError(new HttpFetchFailed('anything', HttpFailure::TooLarge)));
        self::assertSame('La redirection du flux est invalide ou trop longue.', $formatter->describeError(new HttpFetchFailed('anything', HttpFailure::TooManyRedirects)));
        self::assertSame('La redirection du flux est invalide ou trop longue.', $formatter->describeError(new HttpFetchFailed('anything', HttpFailure::BadRedirect)));
        self::assertSame('Le site a mis trop de temps à répondre.', $formatter->describeError(new HttpFetchFailed('Request failed: Operation timed out', HttpFailure::Transport)));
        self::assertSame('Le nom de domaine du site ne peut pas être résolu.', $formatter->describeError(new HttpFetchFailed('Request failed: Could not resolve host: a.test', HttpFailure::Transport)));
        self::assertSame('Le flux est introuvable sur le site (HTTP 404).', $formatter->describeError(new \RuntimeException('Feed request returned HTTP 404.')));
    }
}
