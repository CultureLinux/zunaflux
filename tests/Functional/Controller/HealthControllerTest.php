<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HealthControllerTest extends WebTestCase
{
    public function testHealthEndpointIsPublicAndReportsDatabaseAndQueues(): void
    {
        $client = static::createClient();
        $client->request('GET', '/healthz');

        self::assertResponseStatusCodeSame(200);
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 8, \JSON_THROW_ON_ERROR);
        self::assertSame('ok', $payload['status']);
        self::assertSame('up', $payload['database']);
        self::assertIsInt($payload['queue']['pending']);
        self::assertIsInt($payload['queue']['failed']);
        self::assertSame(['status', 'database', 'queue'], array_keys($payload));
    }
}
