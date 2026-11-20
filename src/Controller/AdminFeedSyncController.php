<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Feed\FeedSyncRunService;
use App\Repository\FeedRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

final class AdminFeedSyncController extends AbstractController
{
    #[Route('/admin/feed-sync', name: 'app_admin_feed_sync_start', methods: ['POST'])]
    public function start(Request $r, FeedRepository $feeds, FeedSyncRunService $syncRuns, TranslatorInterface $translator): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        $csrfToken = $r->headers->get('X-CSRF-Token') ?? $r->request->getString('_token');
        if (!$this->isCsrfTokenValid('admin-feed-sync', $csrfToken)) {
            throw $this->createAccessDeniedException();
        }
        $scope = $r->request->getString('scope');
        $target = match ($scope) {
            'category' => $r->request->getString('category'),
            'feed' => $r->request->getString('feed'),
            default => null,
        };
        if (!in_array($scope, ['all', 'category', 'feed'], true) || ('all' !== $scope && !Uuid::isValid((string) $target))) {
            throw $this->createNotFoundException();
        }
        $ids = $feeds->findIdsForSync($scope, null === $target ? null : Uuid::fromString($target));
        $run = $syncRuns->start($ids);
        if ('application/json' !== $r->headers->get('Accept')) {
            $this->addFlash('success', $translator->trans('%count% flux placés en file de synchronisation.', ['%count%' => count($ids)]));

            return $this->redirectToRoute('app_admin', status: Response::HTTP_SEE_OTHER);
        }

        return $this->json(['runId' => (string) $run, 'count' => count($ids)], 202);
    }

    #[Route('/admin/feed-sync/{id}', name: 'app_admin_feed_sync_status', methods: ['GET'])]
    public function status(string $id, FeedSyncRunService $syncRuns, \App\Twig\IncidentExtension $incidentDescriber): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        if (!Uuid::isValid($id)) {
            throw $this->createNotFoundException();
        }
        $rows = $syncRuns->entries(Uuid::fromString($id));
        $entries = array_map(
            static fn (array $row): array => [...$row, 'detail' => is_string($row['detail']) ? $incidentDescriber->translate($row['detail']) : $row['detail']],
            $rows,
        );

        return $this->json(['entries' => $entries, 'complete' => FeedSyncRunService::isComplete($rows)]);
    }
}
