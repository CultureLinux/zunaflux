<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\User;
use App\Entity\UserPreference;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class FeedExplorerControllerTest extends WebTestCase
{
    public function testExplorerShowsTranslatedCategoriesFlagsAndRightAlignedAddActions(): void
    {
        $client = static::createClient();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $admin = new User('feed-explorer-'.bin2hex(random_bytes(8)).'@example.test');
        $admin->addRole('ROLE_ADMIN');
        $preference = new UserPreference($admin);
        $preference->update(['locale' => 'en']);
        $entityManager->persist($admin);
        $entityManager->persist($preference);
        $entityManager->flush();

        try {
            $client->loginUser($admin);
            $client->request('GET', '/explorer');

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('h1', 'Explore sources');
            self::assertSelectorTextContains('[data-feed-category="actualites"]', 'News');
            self::assertSelectorTextContains('[data-feed-language="en"]', '🇬🇧');
            self::assertSelectorExists('article > .flex.items-center > [data-feed-add].ml-auto[aria-label] svg[aria-hidden="true"]');

            $client->request('GET', '/explorer?language=fr');

            self::assertResponseIsSuccessful();
            self::assertSelectorExists('[data-feed-language="fr"]');
            self::assertSelectorNotExists('[data-feed-language="en"]');
            self::assertSelectorNotExists('[data-feed-category="sport"]');
            self::assertSelectorExists('[data-language-reset] svg[aria-hidden="true"]');
            self::assertSelectorCount(1, '[data-language-filter]');
            self::assertSelectorExists('[data-language-filter="fr"][aria-current="page"]');
            self::assertSelectorNotExists('[data-language-filter="en"], [data-language-filter="de"]');

            $client->request('GET', '/explorer?language=fr&category=cybersecurite');

            self::assertResponseIsSuccessful();
            self::assertSelectorExists('[data-feed-category="cybersecurite"]');
            self::assertSelectorNotExists('[data-feed-category="actualites"]');
            self::assertSelectorExists('[data-category-filter-reset][href="/explorer?language=fr"] svg[aria-hidden="true"]');
        } finally {
            $entityManager->getConnection()->executeStatement('DELETE FROM app_user WHERE id = ?', [$admin->getId()->toBinary()]);
        }
    }
}
