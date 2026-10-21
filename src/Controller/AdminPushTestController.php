<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Notification\PushEndpoint;
use App\Application\Notification\NotificationDiagnostics;
use App\Entity\PushSubscription;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Nyholm\Psr7\Factory\Psr17Factory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class AdminPushTestController extends AbstractController
{
    #[Route('/admin/push-test', name: 'app_admin_push_test', methods: ['POST'])]
    public function __invoke(Request $request, EntityManagerInterface $em, string $pushPrivateFile, string $pushSubject, NotificationDiagnostics $diagnostics): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        if (!$this->isCsrfTokenValid('push-subscription', $request->headers->get('X-CSRF-Token', ''))) throw $this->createAccessDeniedException();
        try { $data = $request->toArray(); } catch (\Throwable) { return $this->json(['error' => 'Données invalides.'], 400); }
        $endpoint = $data['endpoint'] ?? null;
        if (!is_string($endpoint) || !PushEndpoint::isAllowed($endpoint)) return $this->json(['error' => 'Abonnement invalide.'], 400);
        $subscription = $em->getRepository(PushSubscription::class)->findOneBy(['endpointHash' => hash('sha256', $endpoint, true)]);
        $user = $this->getUser();
        if (!$subscription instanceof PushSubscription || !$user instanceof User || !$subscription->getUser()->getId()->equals($user->getId())) return $this->json(['error' => 'Activez les notifications sur cet appareil.'], 404);
        $fileReadable = is_file($pushPrivateFile) && is_readable($pushPrivateFile);
        if (!$fileReadable || '' === $pushSubject) {
            $diagnostics->configuration('test', $fileReadable, '' !== $pushSubject);
            return $this->json(['error' => 'Notifications non configurées.'], 503);
        }
        $stage = 'initialisation';
        try {
            $factory = new Psr17Factory();
            $client = new Psr18Client(HttpClient::create(['max_redirects' => 0, 'timeout' => 10]), $factory, $factory);
            $push = new WebPush(['VAPID' => ['subject' => $pushSubject, 'pemFile' => $pushPrivateFile]], [], $client, $factory, $factory);
            $stage = 'envoi';
            $recipient = Subscription::create(['endpoint' => $endpoint, 'keys' => ['p256dh' => $subscription->getPublicKey(), 'auth' => $subscription->getAuthSecret()], 'contentEncoding' => 'aes128gcm']);
            $report = $push->sendOneNotification($recipient, json_encode(['body' => 'Ceci est une notification de test Zunaflux.', 'tag' => 'zunaflux-test'], JSON_THROW_ON_ERROR));
            if ($report->isSubscriptionExpired()) { $em->remove($subscription); $em->flush(); }
            if (!$report->isSuccess()) {
                $diagnostics->failure('test', $report);
                return $this->json(['error' => 'La notification n’a pas pu être envoyée.'], 502);
            }
        } catch (\Throwable $exception) {
            $diagnostics->exception('test', $stage, $exception);
            return $this->json(['error' => 'La notification n’a pas pu être envoyée.'], 502);
        }

        return $this->json(['ok' => true]);
    }
}
