<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Repository\UserRepository;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
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

    /** Removing account creation, CSRF protection or password hashing must make this contract fail. */
    public function testVisitorCanRegisterANewAccount(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/register');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[action="/register"] input[name="_token"]');

        $email = 'register-'.bin2hex(random_bytes(8)).'@example.test';
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        try {
            $client->submit($crawler->selectButton('Créer mon compte')->form(), [
                'email' => $email,
                'password' => 'a-secure-password',
            ]);

            self::assertResponseRedirects('/login');

            $user = static::getContainer()->get(UserRepository::class)->findOneBy(['emailNormalized' => $email]);
            self::assertNotNull($user);
            self::assertNotSame('a-secure-password', $user->getPassword());
        } finally {
            $entityManager->getConnection()->executeStatement('DELETE FROM app_user WHERE email_normalized = ?', [$email]);
        }
    }

    public function testLogoutRequiresAValidCsrfTokenAndEndsTheSession(): void
    {
        $client = static::createClient();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $user = new User('logout-'.bin2hex(random_bytes(8)).'@example.test');
        $entityManager->persist($user);
        $entityManager->flush();

        try {
            $client->loginUser($user);
            $client->request('POST', '/logout', ['_csrf_token' => 'invalid']);
            self::assertResponseStatusCodeSame(403);
            $crawler = $client->request('GET', '/');
            self::assertSelectorExists('form[action="/logout"][method="post"] input[name="_csrf_token"]');
            $client->submit($crawler->selectButton('Quitter')->form());
            self::assertResponseRedirects('/');
            $client->request('GET', '/articles');
            self::assertResponseRedirects('/login');
        } finally {
            $entityManager->getConnection()->executeStatement('DELETE FROM app_user WHERE id = ?', [$user->getId()->toBinary()]);
        }
    }

    public function testAdministrationRequiresTheAdministratorRole(): void
    {
        $client = static::createClient();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $user = new User('admin-boundary-'.bin2hex(random_bytes(8)).'@example.test');
        $entityManager->persist($user); $entityManager->flush();
        try {
            $client->loginUser($user); $client->request('GET', '/admin');
            self::assertResponseStatusCodeSame(403);
        } finally { $entityManager->getConnection()->executeStatement('DELETE FROM app_user WHERE id = ?', [$user->getId()->toBinary()]); }
    }
}
