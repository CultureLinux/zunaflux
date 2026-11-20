<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CspReportTest extends WebTestCase
{
    public function testAViolationReportIsAcceptedWithoutAuthentication(): void
    {
        $client = static::createClient();
        static::getContainer()->get('limiter.csp_report')->create('127.0.0.1')->reset();

        $client->request('POST', '/csp-report', server: ['CONTENT_TYPE' => 'application/csp-report'], content: json_encode(['csp-report' => [
            'document-uri' => 'https://zunaflux.test/articles?token=secret',
            'violated-directive' => "script-src 'self'",
            'blocked-uri' => 'inline',
        ]], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(204);
    }

    public function testMalformedOversizedAndForeignContentIsRefused(): void
    {
        $client = static::createClient();
        static::getContainer()->get('limiter.csp_report')->create('127.0.0.1')->reset();

        $client->request('POST', '/csp-report', server: ['CONTENT_TYPE' => 'application/csp-report'], content: '{broken');
        self::assertResponseStatusCodeSame(400);
        $client->request('POST', '/csp-report', server: ['CONTENT_TYPE' => 'text/plain'], content: '{}');
        self::assertResponseStatusCodeSame(415);
        $client->request('POST', '/csp-report', server: ['CONTENT_TYPE' => 'application/csp-report'], content: str_repeat('a', 9000));
        self::assertResponseStatusCodeSame(413);
        $client->request('GET', '/csp-report');
        self::assertResponseStatusCodeSame(405);
    }

    public function testTheEndpointIsRateLimitedPerClient(): void
    {
        $client = static::createClient();
        static::getContainer()->get('limiter.csp_report')->create('127.0.0.1')->reset();

        for ($i = 0; $i < 30; ++$i) {
            $client->request('POST', '/csp-report', server: ['CONTENT_TYPE' => 'application/csp-report'], content: '{"csp-report":{}}');
            self::assertResponseStatusCodeSame(204);
        }
        $client->request('POST', '/csp-report', server: ['CONTENT_TYPE' => 'application/csp-report'], content: '{"csp-report":{}}');
        self::assertResponseStatusCodeSame(429);
        static::getContainer()->get('limiter.csp_report')->create('127.0.0.1')->reset();
    }

    public function testPagesAdvertiseThePolicyAndTheirInlineScriptsCarryTheSameNonce(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = new User('csp-'.bin2hex(random_bytes(6)).'@example.test');
        $em->persist($user);
        $em->flush();

        try {
            $client->loginUser($user);
            $crawler = $client->request('GET', '/articles');
            $policy = (string) $client->getResponse()->headers->get('Content-Security-Policy-Report-Only');
            self::assertSame(1, preg_match("/script-src 'self' 'nonce-([A-Za-z0-9+\\/=]+)'/", $policy, $matches));
            $inline = $crawler->filter('script:not([src]):not([type="application/json"])');
            self::assertGreaterThan(0, $inline->count(), 'The reading page has inline scripts (import map).');
            foreach ($inline as $script) {
                self::assertSame($matches[1], $script->getAttribute('nonce'), 'Every executable inline script must carry the nonce.');
            }
        } finally {
            $em->getConnection()->executeStatement('DELETE FROM app_user WHERE id = ?', [$user->getId()->toBinary()]);
        }
    }
}
