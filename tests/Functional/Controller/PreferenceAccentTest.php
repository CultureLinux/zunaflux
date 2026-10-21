<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PreferenceAccentTest extends WebTestCase
{
    public function testSavedAccentIsAppliedAcrossPages(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = new User('accent-'.bin2hex(random_bytes(8)).'@example.test');
        $em->persist($user);
        $em->flush();

        try {
            $client->loginUser($user);
            $crawler = $client->request('GET', '/preferences');
            self::assertSame('cyan', $crawler->filter('html')->attr('data-accent'));
            $form = $crawler->selectButton('Enregistrer')->form();
            $form['accent'] = 'orange';
            $client->submit($form);
            self::assertResponseRedirects();
            $crawler = $client->followRedirect();
            self::assertSame('orange', $crawler->filter('html')->attr('data-accent'));
            $crawler = $client->request('GET', '/subscriptions');
            self::assertSame('orange', $crawler->filter('html')->attr('data-accent'));
            $crawler = $client->request('GET', '/preferences');
            self::assertCount(10, $crawler->filter('select[name="accent"] option'));
            $form = $crawler->selectButton('Enregistrer')->form();
            $form['accent'] = 'violet';
            $client->submit($form);
            self::assertResponseRedirects();
            self::assertSame('violet', $client->followRedirect()->filter('html')->attr('data-accent'));
        } finally {
            $em->getConnection()->executeStatement('DELETE FROM app_user WHERE id = ?', [$user->getId()->toBinary()]);
        }
    }
}
