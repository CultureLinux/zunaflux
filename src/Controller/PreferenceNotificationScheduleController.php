<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Entity\UserPreference;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PreferenceNotificationScheduleController extends AbstractController
{
    #[Route('/preferences/notification-schedule', name: 'app_preferences_notification_schedule', methods: ['POST'])]
    public function __invoke(Request $request, EntityManagerInterface $em): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }
        if (!$this->isCsrfTokenValid('notification-schedule', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $preference = $em->getRepository(UserPreference::class)->findOneBy(['user' => $user]) ?? new UserPreference($user);
        $data = $request->request->all();
        $mode = $data['notification_mode'] ?? '';
        $interval = $data['notification_interval'] ?? '1';
        $times = $data['notification_times'] ?? [];
        try {
            if (!is_string($mode) || !is_string($interval) || !in_array($interval, ['1', '2', '3', '4', '5', '6', '12'], true) || !is_array($times)) {
                throw new \App\Application\Exception\UserInputError('Choisissez une fréquence proposée.');
            }
            foreach ($times as $time) {
                if (!is_string($time) || !in_array($time, ['8', '12', '18', '22'], true)) {
                    throw new \App\Application\Exception\UserInputError('Choisissez une heure proposée.');
                }
            }
            $preference->setNotificationSchedule($mode, (int) $interval, array_map('intval', $times));
        } catch (\InvalidArgumentException $error) {
            return $this->render(
                'preference/schedule_error.html.twig',
                ['preference' => $preference, 'schedule_error' => $error->getMessage()],
                new Response(status: 422),
            );
        }
        $em->persist($preference);
        $em->flush();
        $this->addFlash('success', 'Rythme des notifications enregistré.');

        return $this->redirect($this->generateUrl('app_preferences').'#preference-notifications');
    }
}
