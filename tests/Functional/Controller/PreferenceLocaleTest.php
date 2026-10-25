<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PreferenceLocaleTest extends WebTestCase
{
    public function testLanguagePreferencePersistsAcrossRequests(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = new User('locale-'.bin2hex(random_bytes(8)).'@example.test');
        $user->addRole('ROLE_ADMIN');
        $em->persist($user);
        $em->flush();

        try {
            $client->loginUser($user);
            $crawler = $client->request('GET', '/preferences');
            self::assertSame('fr', $crawler->filter('html')->attr('lang'));
            $form = $crawler->selectButton('Enregistrer')->form();
            $form['locale'] = 'de';
            $client->submit($form);
            $crawler = $client->followRedirect();
            self::assertSame('de', $crawler->filter('html')->attr('lang'));
            self::assertStringContainsString('Einstellungen', $crawler->filter('h1')->text());
            self::assertSelectorTextContains('body', 'Nachtruhe');
            self::assertSelectorTextContains('body', 'Nachtruhe speichern');
            $crawler = $client->request('GET', '/subscriptions');
            self::assertSame('de', $crawler->filter('html')->attr('lang'));
            self::assertSelectorTextContains('label[for="site_url"]', 'Feeds einer Website entdecken');
            self::assertSelectorTextContains('label[for="direct-category"]', 'Kategorie');
            self::assertSelectorTextContains('label[for="category-search"]', 'Kategorien und Feeds filtern');
            self::assertStringContainsString('FreshRSS-kompatibel', $crawler->filter('body')->text());
            $client->request('GET', '/about');
            self::assertSelectorTextContains('body', 'Sie können CultureLinux auf Ko-fi unterstützen.');
            self::assertSelectorTextContains('body', 'Sie haben einen Fehler gefunden oder möchten eine Verbesserung vorschlagen?');
            self::assertSelectorTextContains('body', 'Kontaktieren Sie uns');
            $client->request('GET', '/admin');
            self::assertSelectorTextContains('h1', 'Konten und Status');
            $crawler = $client->request('GET', '/preferences');
            $form = $crawler->selectButton('Speichern')->form();
            $form['locale'] = 'en';
            $client->submit($form);
            $client->request('GET', '/subscriptions');
            self::assertSelectorTextContains('label[for="site_url"]', 'Discover feeds on a website');
            self::assertSelectorTextContains('label[for="direct-category"]', 'Category');
            self::assertSelectorTextContains('label[for="category-search"]', 'Filter categories and feeds');
            $client->request('GET', '/about');
            self::assertSelectorTextContains('body', 'You can support CultureLinux on Ko-fi.');
            self::assertSelectorTextContains('body', 'Did you encounter a bug or want to suggest an improvement?');
            self::assertSelectorTextContains('body', 'Contact us');
            $client->request('GET', '/preferences');
            self::assertSelectorTextContains('body', 'Quiet hours');
            self::assertSelectorTextContains('body', 'Save quiet hours');
            $client->request('GET', '/admin');
            self::assertSelectorTextContains('h1', 'Accounts & health');
        } finally {
            $em->getConnection()->executeStatement('DELETE FROM app_user WHERE id = ?', [$user->getId()->toBinary()]);
        }
    }
}
