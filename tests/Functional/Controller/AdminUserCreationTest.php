<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminUserCreationTest extends WebTestCase
{
    public function testAdministratorCreatesAnAccountAndADuplicateIsReportedWithoutALeakyMessage(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $suffix = bin2hex(random_bytes(8));
        $admin = new User('admin-create-'.$suffix.'@example.test');
        $admin->addRole('ROLE_ADMIN');
        $em->persist($admin);
        $em->flush();
        $created = 'created-'.$suffix.'@example.test';

        try {
            $client->loginUser($admin);
            $crawler = $client->request('GET', '/admin');
            self::assertResponseIsSuccessful();
            $token = $crawler->filter('form[action="/admin/users"] input[name="_token"]')->attr('value');

            $client->request('POST', '/admin/users', ['_token' => $token, 'email' => $created, 'password' => 'a-secure-password']);
            self::assertResponseRedirects('/admin');
            self::assertSelectorNotExists('.error');
            self::assertSame(1, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM app_user WHERE email_normalized = ?', [$created]));

            $client->request('POST', '/admin/users', ['_token' => $token, 'email' => $created, 'password' => 'a-secure-password']);
            self::assertResponseRedirects('/admin');
            $client->followRedirect();
            self::assertSelectorTextContains('body', 'Un compte existe déjà');
            self::assertSelectorTextNotContains('body', 'SQLSTATE');
        } finally {
            $em->getConnection()->executeStatement('DELETE FROM app_user WHERE email_normalized IN (?, ?)', [$created, $admin->getUserIdentifier()]);
        }
    }
}
