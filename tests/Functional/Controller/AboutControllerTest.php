<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AboutControllerTest extends WebTestCase
{
    public function testAboutPageShowsProjectLinks(): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = new User('about-'.bin2hex(random_bytes(8)).'@example.test');
        $em->persist($user);
        $em->flush();
        try {
            $client->loginUser($user);
            $client->request('GET', '/about');
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('a[href="https://github.com/CultureLinux/zunaflux"]');
            self::assertSelectorExists('a[href="https://github.com/CultureLinux/zunaflux/issues"]');
            self::assertSelectorExists('a[href="https://ko-fi.com/culturelinux"]');
        } finally {
            $em->getConnection()->executeStatement('DELETE FROM app_user WHERE id = ?', [$user->getId()->toBinary()]);
        }
    }
}
