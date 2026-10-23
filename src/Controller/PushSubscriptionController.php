<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Notification\PushEndpoint;
use App\Application\Notification\NotificationDiagnostics;
use App\Application\Notification\PushDeviceLabel;
use App\Entity\PushSubscription;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class PushSubscriptionController extends AbstractController
{
    #[Route('/api/push-subscription/status', name: 'app_push_subscription_status', methods: ['POST'])]
    public function status(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User || !$this->isCsrfTokenValid('push-subscription', $request->headers->get('X-CSRF-Token', ''))) {
            return $this->json(['error' => 'Accès refusé.'], 403);
        }
        try { $data = $request->toArray(); } catch (\Throwable) { return $this->json(['error' => 'Données invalides.'], 400); }
        $endpoint = $data['endpoint'] ?? null;
        if (!is_string($endpoint) || !PushEndpoint::isAllowed($endpoint)) return $this->json(['error' => 'Abonnement invalide.'], 400);
        $subscription = $em->getRepository(PushSubscription::class)->findOneBy(['endpointHash' => hash('sha256', $endpoint, true)]);

        $owned = $subscription instanceof PushSubscription && $subscription->getUser()->getId()->equals($user->getId());
        if ($owned) {
            $subscription->setDeviceLabel(PushDeviceLabel::fromUserAgent($request->headers->get('User-Agent', '')));
            $em->flush();
        }

        return $this->json([
            'subscribed' => $owned,
            'missing' => !$subscription instanceof PushSubscription,
            'deviceId' => $owned ? (string) $subscription->getId() : null,
            'deviceLabel' => $owned ? $subscription->getDeviceLabel() : null,
        ]);
    }

    #[Route('/api/push-subscription', name: 'app_push_subscription', methods: ['POST', 'DELETE'])]
    public function subscription(Request $request, EntityManagerInterface $em, NotificationDiagnostics $diagnostics): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User || !$this->isCsrfTokenValid('push-subscription', $request->headers->get('X-CSRF-Token', ''))) {
            return $this->json(['error' => 'Accès refusé.'], 403);
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->json(['error' => 'Données invalides.'], 400);
        }
        $endpoint = $data['endpoint'] ?? null;
        if (!is_string($endpoint) || !PushEndpoint::isAllowed($endpoint)) {
            return $this->json(['error' => 'Abonnement invalide.'], 400);
        }
        $subscription = $em->getRepository(PushSubscription::class)->findOneBy(['endpointHash' => hash('sha256', $endpoint, true)]);
        if ($request->isMethod('DELETE')) {
            if ($subscription instanceof PushSubscription && $subscription->getUser()->getId()->equals($user->getId())) {
                $em->remove($subscription);
                $em->flush();
                $diagnostics->subscriptionDeactivated();
            }

            return $this->json(['ok' => true]);
        }

        $key = $data['keys']['p256dh'] ?? null;
        $secret = $data['keys']['auth'] ?? null;
        if (!is_string($key) || !is_string($secret) || !preg_match('/^[A-Za-z0-9_-]{87}$/D', $key) || !preg_match('/^[A-Za-z0-9_-]{22}$/D', $secret)) {
            return $this->json(['error' => 'Clés invalides.'], 400);
        }
        if ($subscription instanceof PushSubscription) {
            if (!$subscription->getUser()->getId()->equals($user->getId()) && (!hash_equals($subscription->getPublicKey(), $key) || !hash_equals($subscription->getAuthSecret(), $secret))) {
                return $this->json(['error' => 'Abonnement indisponible.'], 403);
            }
            $subscription->update($user, $key, $secret);
        } else {
            $subscription = new PushSubscription($user, $endpoint, $key, $secret);
            $em->persist($subscription);
        }
        $subscription->setDeviceLabel(PushDeviceLabel::fromUserAgent($request->headers->get('User-Agent', '')));
        $em->flush();
        $diagnostics->subscriptionActivated();

        return $this->json(['ok' => true]);
    }
}
