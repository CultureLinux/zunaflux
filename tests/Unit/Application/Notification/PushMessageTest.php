<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Notification;

use App\Application\Notification\PushMessage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Loader\JsonFileLoader;
use Symfony\Component\Translation\Translator;

final class PushMessageTest extends TestCase
{
    public function testMessagesUseTheRecipientLanguageAndArticleCount(): void
    {
        $translator = new Translator('fr');
        $translator->addLoader('json', new JsonFileLoader());
        foreach (['en', 'de'] as $locale) {
            $translator->addResource('json', dirname(__DIR__, 4).'/translations/messages.'.$locale.'.json', $locale);
        }
        $message = new PushMessage($translator);

        self::assertSame('1 nouvel article dans vos sources.', $message->articles(1, 'fr'));
        self::assertSame('2 nouveaux articles dans vos sources.', $message->articles(2, 'fr'));
        self::assertSame('1 new article in your sources.', $message->articles(1, 'en'));
        self::assertSame('2 new articles in your sources.', $message->articles(2, 'en'));
        self::assertSame('1 neuer Artikel in Ihren Quellen.', $message->articles(1, 'de'));
        self::assertSame('2 neue Artikel in Ihren Quellen.', $message->articles(2, 'de'));
        self::assertSame('A source encountered a synchronization error.', $message->error('en'));
        self::assertSame('Bei einer Quelle ist ein Synchronisierungsfehler aufgetreten.', $message->error('de'));
        self::assertSame('This is a Zunaflux test notification.', $message->test('en'));
        self::assertSame('Dies ist eine Zunaflux-Testbenachrichtigung.', $message->test('de'));
    }
}
