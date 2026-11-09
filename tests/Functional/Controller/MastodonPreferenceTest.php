<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class MastodonPreferenceTest extends WebTestCase
{
    public function testPreferencesOfferAMastodonConnectionForm(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = new User('mastodon-preference-'.bin2hex(random_bytes(8)).'@example.test');
        $em->persist($user);
        $em->flush();

        try {
            $client->loginUser($user);
            $crawler = $client->request('GET', '/preferences');

            self::assertSelectorExists('[data-preference-tabs][role="tablist"]');
            self::assertSelectorExists('[data-preference-tab="general"][role="tab"]');
            self::assertSelectorExists('[data-preference-tab="notifications"][role="tab"]');
            self::assertSelectorExists('[data-preference-tab="mastodon"][role="tab"]');
            self::assertSelectorExists('[data-preference-tab="data"][role="tab"]');
            self::assertSelectorExists('#preference-general[role="tabpanel"]');
            self::assertSelectorExists('#preference-mastodon[role="tabpanel"]');
            self::assertSelectorExists('form[action="/mastodon/connect"] input[name="instance_url"]');
            self::assertSelectorExists('form[action="/mastodon/connect"] input[name="_token"]');
            self::assertSelectorTextContains('body', 'Mastodon');
        } finally {
            $em->getConnection()->executeStatement('DELETE FROM app_user WHERE id = ?', [$user->getId()->toBinary()]);
        }
    }
}
