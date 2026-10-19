<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SecurityControllerTest extends WebTestCase
{
    /** Removing CSRF protection from the login boundary must make this contract fail. */
    public function testTheLoginPageProvidesAnAccessibleCsrfProtectedForm(): void
    {
        $client = static::createClient();
        $client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[action="/login"] input[name="_csrf_token"]');
        self::assertSelectorTextContains('h1', 'Bienvenue dans Zunaflux');
    }
}
