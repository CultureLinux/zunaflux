<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\User;
use App\Entity\UserPreference;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class EnglishInterfaceTest extends WebTestCase
{
    public function testReadingAndAdministrationPagesAreTranslatedForAnEnglishUser(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $admin = new User('english-'.bin2hex(random_bytes(8)).'@example.test');
        $admin->addRole('ROLE_ADMIN');
        $preference = new UserPreference($admin);
        $preference->update(['locale' => 'en']);
        $em->persist($admin);
        $em->persist($preference);
        $em->flush();

        try {
            $client->loginUser($admin);
            $client->request('GET', '/articles');
            self::assertResponseIsSuccessful();
            $html = (string) $client->getResponse()->getContent();
            foreach (['title="Mark as read"', 'title="Add to favorites"', 'title="Read later"', 'title="Share the article"', 'Skip to content'] as $expected) {
                self::assertStringContainsString($expected, $html);
            }
            foreach (['title="Marquer lu"', 'title="Lire plus tard"', 'Sélectionner les articles chargés'] as $french) {
                self::assertStringNotContainsString($french, $html);
            }
            self::assertStringContainsString('Could not mark this article as read. Please try again.', $html);

            $client->request('GET', '/admin');
            self::assertResponseIsSuccessful();
            $html = (string) $client->getResponse()->getContent();
            self::assertMatchesRegularExpression('/Worker (stopped|running)/', $html);
            self::assertStringNotContainsString('Worker arrêté', $html);
            self::assertStringNotContainsString('Worker actif', $html);
            self::assertStringNotContainsString('en attente', $html);
        } finally {
            $em->getConnection()->executeStatement('DELETE FROM app_user WHERE id = ?', [$admin->getId()->toBinary()]);
        }
    }
}
