<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\User;
use App\Entity\UserPreference;
use App\Tests\Support\CsrfTokens;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PreferenceRoutesTest extends WebTestCase
{
    use CsrfTokens;

    private ?User $user = null;

    protected function tearDown(): void
    {
        if (null !== $this->user) {
            static::getContainer()->get(EntityManagerInterface::class)->getConnection()->executeStatement('DELETE FROM app_user WHERE id = ?', [$this->user->getId()->toBinary()]);
        }
        parent::tearDown();
    }

    public function testQuietHoursAreSavedClearedAndProtectedAgainstForgedRequests(): void
    {
        $client = static::createClient();
        $this->user = $this->createUser();
        $client->loginUser($this->user);
        $token = $this->csrfToken($client, 'quiet-hours');

        $client->request('POST', '/preferences/quiet-hours', ['_token' => 'forged', 'quiet_start' => '22:00', 'quiet_end' => '07:00']);
        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->preference()?->getQuietStart());

        $client->request('POST', '/preferences/quiet-hours', ['_token' => $token, 'quiet_start' => '22:00', 'quiet_end' => '07:00']);
        self::assertResponseRedirects('/preferences');
        self::assertSame(['22:00', '07:00'], [$this->preference()?->getQuietStart(), $this->preference()?->getQuietEnd()]);
        $client->followRedirect();
        self::assertSelectorTextContains('.toast', 'Plage de silence enregistrée.');
    }

    public function testInvalidQuietHoursAreIgnoredAndTheClearActionResetsThem(): void
    {
        $client = static::createClient();
        $this->user = $this->createUser();
        $client->loginUser($this->user);
        $token = $this->csrfToken($client, 'quiet-hours');
        $client->request('POST', '/preferences/quiet-hours', ['_token' => $token, 'quiet_start' => '22:00', 'quiet_end' => '07:00']);

        $client->request('POST', '/preferences/quiet-hours', ['_token' => $token, 'quiet_start' => '25:99', 'quiet_end' => 'later']);
        self::assertResponseRedirects('/preferences');
        self::assertSame(['22:00', '07:00'], [$this->preference()?->getQuietStart(), $this->preference()?->getQuietEnd()]);

        $client->request('POST', '/preferences/quiet-hours', ['_token' => $token, 'clear' => '1']);
        self::assertResponseRedirects('/preferences');
        self::assertSame([null, null], [$this->preference()?->getQuietStart(), $this->preference()?->getQuietEnd()]);
    }

    public function testQuietHoursRequireAnAuthenticatedUser(): void
    {
        $client = static::createClient();
        $client->request('POST', '/preferences/quiet-hours', ['_token' => 'x']);

        self::assertContains($client->getResponse()->getStatusCode(), [302, 401, 403]);
    }

    public function testThePageLanguageFollowsTheSavedPreference(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->user = $this->createUser();
        $preference = new UserPreference($this->user);
        $preference->update(['locale' => 'de']);
        $em->persist($preference);
        $em->flush();

        $client->loginUser($this->user);
        $crawler = $client->request('GET', '/preferences');

        self::assertSame('de', $crawler->filter('html')->attr('lang'));
        self::assertStringContainsString('Einstellungen', (string) $client->getResponse()->getContent());
    }

    private function createUser(): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = new User('prefs-'.bin2hex(random_bytes(6)).'@example.test');
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function preference(): ?UserPreference
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        return $em->getRepository(UserPreference::class)->findOneBy(['user' => $this->user]);
    }
}
