<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\User;
use App\Entity\UserPreference;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class NotificationScheduleTest extends WebTestCase
{
    public function testScheduleIsSavedForTheAuthenticatedAccountAndInvalidInputIsRejected(): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = new User('schedule-'.bin2hex(random_bytes(8)).'@example.test');
        $other = new User('schedule-other-'.bin2hex(random_bytes(8)).'@example.test');
        foreach ([$user, $other, new UserPreference($other)] as $entity) {
            $em->persist($entity);
        }
        $em->flush();
        try {
            $client->loginUser($user);
            $page = $client->request('GET', '/preferences');
            $token = $page->filter('[data-notification-schedule] input[name="_token"]')->attr('value');
            self::assertSelectorCount(4, '[name="notification_times[]"]');
            $client->request('POST', '/preferences/notification-schedule', ['_token' => $token, 'notification_mode' => 'fixed', 'notification_interval' => '1', 'notification_times' => ['8', '18']]);
            self::assertResponseRedirects('/preferences#preference-notifications');
            $em = self::getContainer()->get(EntityManagerInterface::class);
            $em->clear();
            $preference = $em->getRepository(UserPreference::class)->findOneBy(['user' => $user->getId()]);
            self::assertSame([8, 18], $preference->getNotificationTimes());
            self::assertSame('fixed', $preference->getNotificationMode());
            self::assertSame('interval', $em->getRepository(UserPreference::class)->findOneBy(['user' => $other->getId()])->getNotificationMode());
            $client->request('POST', '/preferences/notification-schedule', ['_token' => $token, 'notification_mode' => 'fixed', 'notification_times' => []]);
            self::assertResponseStatusCodeSame(422);
            self::assertSelectorExists('[data-notification-schedule] [role="alert"]');
            $client->request('POST', '/preferences/notification-schedule', ['_token' => 'invalid']);
            self::assertResponseStatusCodeSame(403);
        } finally {
            self::getContainer()->get(EntityManagerInterface::class)->getConnection()->executeStatement('DELETE FROM app_user WHERE id IN (?, ?)', [$user->getId()->toBinary(), $other->getId()->toBinary()]);
        }
    }
}
