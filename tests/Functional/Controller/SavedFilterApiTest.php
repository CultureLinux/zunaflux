<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\SavedFilter;
use App\Entity\User;
use App\Tests\Support\CsrfTokens;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SavedFilterApiTest extends WebTestCase
{
    use CsrfTokens;

    /** @var list<User> */
    private array $users = [];

    protected function tearDown(): void
    {
        if ([] !== $this->users) {
            $db = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
            foreach ($this->users as $user) {
                $db->executeStatement('DELETE FROM app_user WHERE id = ?', [$user->getId()->toBinary()]);
            }
        }
        parent::tearDown();
    }

    public function testAnonymousVisitorsAreRefused(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/saved-filters');

        self::assertContains($client->getResponse()->getStatusCode(), [302, 401, 403]);
    }

    public function testCreatingListingRenamingAndDeletingAFilter(): void
    {
        [$client, $token] = $this->signedIn();

        $this->json($client, 'POST', '/api/saved-filters', ['name' => 'Zeta', 'filters' => ['read' => '0', 'q' => 'rss', 'nested' => ['x']]], $token);
        self::assertResponseStatusCodeSame(201);
        $zeta = json_decode((string) $client->getResponse()->getContent(), true)['id'];
        $this->json($client, 'POST', '/api/saved-filters', ['name' => 'Alpha', 'filters' => []], $token);
        self::assertResponseStatusCodeSame(201);

        $client->request('GET', '/api/saved-filters');
        $list = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame(['Alpha', 'Zeta'], array_column($list, 'name'));
        // Non-scalar criteria are dropped.
        self::assertSame(['read' => '0', 'q' => 'rss'], $list[1]['filters']);

        $this->json($client, 'PATCH', '/api/saved-filters/'.$zeta, ['name' => 'Omega'], $token);
        self::assertResponseIsSuccessful();
        self::assertSame('Omega', json_decode((string) $client->getResponse()->getContent(), true)['name']);

        $this->json($client, 'DELETE', '/api/saved-filters/'.$zeta, [], $token);
        self::assertResponseStatusCodeSame(204);
        $client->request('GET', '/api/saved-filters');
        self::assertSame(['Alpha'], array_column(json_decode((string) $client->getResponse()->getContent(), true), 'name'));
    }

    public function testWritesRequireAValidCsrfToken(): void
    {
        [$client] = $this->signedIn();

        $this->json($client, 'POST', '/api/saved-filters', ['name' => 'Nope', 'filters' => []], 'invalid');

        self::assertResponseStatusCodeSame(403);
    }

    public function testMalformedInputIsRejectedWithA4xxNotA500(): void
    {
        [$client, $token] = $this->signedIn();

        $client->request('POST', '/api/saved-filters', server: ['HTTP_X_CSRF_TOKEN' => $token, 'CONTENT_TYPE' => 'application/json'], content: '{broken');
        self::assertResponseStatusCodeSame(400);
        $this->json($client, 'POST', '/api/saved-filters', ['name' => '', 'filters' => []], $token);
        self::assertResponseStatusCodeSame(422);
        $this->json($client, 'POST', '/api/saved-filters', ['name' => 'Bad', 'filters' => 'text'], $token);
        self::assertResponseStatusCodeSame(422);
    }

    public function testAFilterBelongsToItsOwnerOnly(): void
    {
        [$client, $token, $owner] = $this->signedIn();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $stranger = new User('stranger-'.bin2hex(random_bytes(6)).'@example.test');
        $this->users[] = $stranger;
        $foreign = new SavedFilter($stranger, 'Private', ['q' => 'secret']);
        $em->persist($stranger);
        $em->persist($foreign);
        $em->flush();

        $client->request('GET', '/api/saved-filters');
        self::assertSame([], json_decode((string) $client->getResponse()->getContent(), true));
        $this->json($client, 'PATCH', '/api/saved-filters/'.$foreign->getId(), ['name' => 'Hijacked'], $token);
        self::assertResponseStatusCodeSame(404);
        $this->json($client, 'DELETE', '/api/saved-filters/'.$foreign->getId(), [], $token);
        self::assertResponseStatusCodeSame(404);
        $this->json($client, 'PATCH', '/api/saved-filters/not-a-uuid', ['name' => 'x'], $token);
        self::assertResponseStatusCodeSame(404);
        self::assertSame('Private', $em->getConnection()->fetchOne('SELECT name FROM saved_filter WHERE id = ?', [$foreign->getId()->toBinary()]));
    }

    /** @return array{KernelBrowser, string, User} */
    private function signedIn(): array
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = new User('filters-'.bin2hex(random_bytes(6)).'@example.test');
        $this->users[] = $user;
        $em->persist($user);
        $em->flush();
        $client->loginUser($user);

        return [$client, $this->csrfToken($client, 'saved-filter'), $user];
    }

    /** @param array<string, mixed> $body */
    private function json(KernelBrowser $client, string $method, string $url, array $body, string $token): void
    {
        $client->request($method, $url, server: ['HTTP_X_CSRF_TOKEN' => $token, 'CONTENT_TYPE' => 'application/json'], content: json_encode($body, \JSON_THROW_ON_ERROR));
    }
}
