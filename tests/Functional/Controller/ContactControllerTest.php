<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ContactControllerTest extends WebTestCase
{
    public function testVisitorCanOpenAndSubmitTheContactForm(): void
    {
        $client = static::createClient();
        $server = $this->server();
        $page = $client->request('GET', '/contact', server: $server);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[action="/contact"] input[name="_token"]');

        $client->submit($page->selectButton('Envoyer le message')->form(), [
            'name' => 'Marie Dupont',
            'email' => 'marie@example.test',
            'subject' => 'Question sur Zunaflux',
            'message' => 'Bonjour, pourriez-vous m’aider ?',
        ], $server);

        self::assertResponseRedirects('/contact');
        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertEmailAddressContains($email, 'To', 'products@clinux.fr');
        self::assertEmailAddressContains($email, 'Reply-To', 'marie@example.test');
        self::assertEmailSubjectContains($email, 'Question sur Zunaflux');
    }

    public function testContactFormRejectsInvalidCsrfAndEmail(): void
    {
        $client = static::createClient();

        $client->request('POST', '/contact', [
            '_token' => 'invalid',
            'name' => 'Marie Dupont',
            'email' => 'not-an-email',
            'subject' => 'Bonjour',
            'message' => 'Message valide.',
        ], server: $this->server());

        self::assertResponseStatusCodeSame(403);
        self::assertEmailCount(0);
    }

    public function testContactFormRejectsAnInvalidReplyAddress(): void
    {
        $client = static::createClient();
        $server = $this->server();
        $page = $client->request('GET', '/contact', server: $server);

        $client->submit($page->selectButton('Envoyer le message')->form(), [
            'name' => 'Marie Dupont',
            'email' => 'not-an-email',
            'subject' => 'Question sur Zunaflux',
            'message' => 'Bonjour, pourriez-vous m’aider ?',
        ], $server);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#contact-email-error', 'adresse e-mail valide');
        self::assertEmailCount(0);
    }

    private function server(): array
    {
        $ip = sprintf('2001:db8:%x:%x::1', random_int(1, 65535), random_int(1, 65535));
        static::getContainer()->get('limiter.contact')->create($ip)->reset();

        return ['REMOTE_ADDR' => $ip];
    }
}
