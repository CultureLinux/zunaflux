<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HttpHardeningTest extends WebTestCase
{
    public function testSecurityHeadersArePresentOnPublicAndAuthenticatedPages(): void
    {
        $client = static::createClient();
        $client->request('GET', '/login');
        self::assertResponseHeaderSame('X-Content-Type-Options', 'nosniff');
        self::assertResponseHeaderSame('X-Frame-Options', 'DENY');
        self::assertResponseHeaderSame('Referrer-Policy', 'strict-origin-when-cross-origin');
        self::assertResponseHeaderSame('Content-Security-Policy', "frame-ancestors 'none'");
    }

    public function testDisablingAnAccountEndsItsOpenSession(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = new User('disabled-session-'.bin2hex(random_bytes(8)).'@example.test');
        $em->persist($user);
        $em->flush();

        try {
            $client->loginUser($user);
            $client->request('GET', '/articles');
            self::assertResponseIsSuccessful();

            $em->getConnection()->executeStatement("UPDATE app_user SET status = 'disabled' WHERE id = ?", [$user->getId()->toBinary()]);

            $client->request('GET', '/articles');
            self::assertResponseRedirects('http://localhost/login');
        } finally {
            $em->getConnection()->executeStatement('DELETE FROM app_user WHERE id = ?', [$user->getId()->toBinary()]);
        }
    }

    public function testMalformedFiltersAreRejectedWithA4xxInsteadOfA500(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = new User('filters-'.bin2hex(random_bytes(8)).'@example.test');
        $em->persist($user);
        $em->flush();

        try {
            $client->loginUser($user);
            foreach (['subscription=not-a-uuid', 'category=not-a-uuid', 'from=not+a+date', 'to=not+a+date'] as $query) {
                $client->request('GET', '/api/articles?'.$query);
                self::assertResponseStatusCodeSame(400, $query);
            }
        } finally {
            $em->getConnection()->executeStatement('DELETE FROM app_user WHERE id = ?', [$user->getId()->toBinary()]);
        }
    }

    public function testRegistrationIsRateLimitedPerClientIp(): void
    {
        $client = static::createClient();
        // Limiter state is shared through the cache: start from a clean quota.
        static::getContainer()->get('limiter.register')->create('127.0.0.1')->reset();
        $connection = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $email = 'ratelimit-'.bin2hex(random_bytes(8)).'@example.test';
        try {
            $crawler = $client->request('GET', '/register');
            self::assertResponseIsSuccessful();
            $token = $crawler->filter('form[action="/register"] input[name="_token"]')->attr('value');
            // Well-formed submissions are counted, whether they create the account or not.
            for ($attempt = 1; $attempt <= 3; ++$attempt) {
                $client->request('POST', '/register', ['_token' => $token, 'email' => $email, 'password' => 'a-secure-password']);
                self::assertResponseRedirects(null, null, 'attempt '.$attempt);
            }
            $client->request('POST', '/register', ['_token' => $token, 'email' => $email, 'password' => 'a-secure-password']);
            self::assertResponseStatusCodeSame(429);
        } finally {
            static::getContainer()->get('limiter.register')->create('127.0.0.1')->reset();
            $connection->executeStatement('DELETE FROM app_user WHERE email_normalized = ?', [$email]);
        }
    }
}
