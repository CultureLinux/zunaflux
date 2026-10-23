<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Notification\NotificationCounter;
use App\Application\Notification\NotificationDiagnostics;
use App\Application\Notification\PushDelivery;
use App\Application\Notification\PushMessage;
use App\Entity\PushExpiredNotice;
use App\Entity\PushSubscription;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

final class PushDeviceController extends AbstractController
{
    #[Route('/preferences/push-device/{id}/{action}', name: 'app_push_device_action', requirements: ['action' => 'test|delete'], methods: ['POST'])]
    public function device(string $id, string $action, Request $request, EntityManagerInterface $em, PushDelivery $delivery, PushMessage $messages, NotificationDiagnostics $diagnostics, NotificationCounter $counter): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User || !$this->isCsrfTokenValid('push-device-'.$id, $request->request->getString('_token'))) throw $this->createAccessDeniedException();
        if (!Uuid::isValid($id)) throw $this->createNotFoundException();
        $subscription = $em->getRepository(PushSubscription::class)->findOneBy(['id' => Uuid::fromString($id), 'user' => $user]);
        if (!$subscription instanceof PushSubscription) throw $this->createNotFoundException();
        if ('delete' === $action) {
            $em->remove($subscription);
            $em->flush();
            if ($request->headers->get('Accept') === 'application/json') return $this->json(['ok' => true]);
            $this->addFlash('success', 'Appareil supprimé des notifications.');
            return $this->redirectToRoute('app_preferences');
        }
        try {
            $payload = json_encode(['body' => $messages->test($request->getLocale()), 'tag' => 'zunaflux-test'], JSON_THROW_ON_ERROR);
            $report = $delivery->send($subscription, $payload);
            $delivery->record($subscription, $report);
            $em->flush();
            if ($report->isSuccess()) {
                $counter->recordAccepted();
                $this->addFlash('success', 'Test accepté par le service de notifications. Vérifiez l’appareil.');
            } else {
                $diagnostics->failure('test-appareil', $report);
                $this->addFlash('error', $report->isSubscriptionExpired() ? 'Cet abonnement a expiré. Réactivez les notifications sur cet appareil.' : 'Le service de notifications a refusé le test.');
            }
        } catch (\Throwable $exception) {
            $subscription->recordResult('failed');
            $em->flush();
            $diagnostics->exception('test-appareil', 'envoi', $exception);
            $this->addFlash('error', 'Impossible d’envoyer le test à cet appareil.');
        }

        return $this->redirectToRoute('app_preferences');
    }

    #[Route('/preferences/push-expired/{id}/dismiss', name: 'app_push_notice_dismiss', methods: ['POST'])]
    public function dismiss(string $id, Request $request, EntityManagerInterface $em): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User || !$this->isCsrfTokenValid('push-notice-'.$id, $request->request->getString('_token'))) throw $this->createAccessDeniedException();
        if (!Uuid::isValid($id)) throw $this->createNotFoundException();
        $notice = $em->getRepository(PushExpiredNotice::class)->findOneBy(['id' => Uuid::fromString($id), 'user' => $user]);
        if (!$notice instanceof PushExpiredNotice) throw $this->createNotFoundException();
        $em->remove($notice);
        $em->flush();
        return $this->redirectToRoute('app_preferences');
    }
}
