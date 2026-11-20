<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Subscription\SubscribeToFeed;
use App\Application\Subscription\CreateCategory;
use App\Application\Subscription\ImportOpml;
use App\Application\Feed\FeedSyncRunService;
use App\Application\Subscription\SubscriptionEditor;
use App\Application\Subscription\SubscriptionRemoval;
use App\Repository\SubscriptionRepository;
use App\Entity\Category;
use App\Entity\Subscription;
use App\Entity\User;
use App\Twig\IncidentExtension;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

final class SubscriptionController extends AbstractController
{
    use ResolvesOwnedEntities;

    public function __construct(private readonly IncidentExtension $incidentDescriber)
    {
    }

    #[Route('/subscriptions', name: 'app_subscriptions', methods: ['GET', 'POST'])]
    public function index(
        Request $request,
        SubscribeToFeed $subscribeToFeed,
        CreateCategory $createCategory,
        ImportOpml $importOpml,
        EntityManagerInterface $entityManager,
        TranslatorInterface $translator,
        SubscriptionRepository $subscriptions,
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }
        if ($request->isMethod('POST')) {
            try {
                if ('create-category' === $request->request->getString('intent')) {
                    if (!$this->isCsrfTokenValid('create-category', $request->request->getString('_token'))) {
                        throw $this->createAccessDeniedException('Invalid CSRF token.');
                    }
                    $createCategory->create($user, $request->request->getString('category_name'));
                    $this->addFlash('success', 'Catégorie créée.');
                } elseif ('import-opml' === $request->request->getString('intent')) {
                    if (!$this->isCsrfTokenValid('import-opml', $request->request->getString('_token'))) {
                        throw $this->createAccessDeniedException('Invalid CSRF token.');
                    }
                    $file = $request->files->get('opml_file');
                    if (!$file instanceof \Symfony\Component\HttpFoundation\File\UploadedFile || !$file->isValid()) {
                        throw new \App\Application\Exception\InvalidOpmlFile('Veuillez sélectionner un fichier OPML valide.');
                    }
                    if ($file->getSize() > 2000000) {
                        throw new \App\Application\Exception\InvalidOpmlFile('Le fichier OPML ne doit pas dépasser 2 Mo.');
                    }
                    $xml = file_get_contents($file->getPathname());
                    if (false === $xml) {
                        throw new \App\Application\Exception\InvalidOpmlFile('Le fichier OPML ne peut pas être lu.');
                    }
                    $report = $importOpml->import($user, $xml);
                    $this->addFlash(
                        'success',
                        $translator->trans(
                            '%imported% flux importé(s), %duplicate% déjà présent(s), %failed% non importé(s).',
                            ['%imported%' => $report['imported'], '%duplicate%' => $report['duplicate'], '%failed%' => $report['failed']],
                        ),
                    );
                } else {
                    if (!$this->isCsrfTokenValid('subscribe-feed', $request->request->getString('_token'))) {
                        throw $this->createAccessDeniedException('Invalid CSRF token.');
                    }
                    $categoryChoice = $request->request->getString('discovery_category');
                    if ('' === $categoryChoice) {
                        $subscribeToFeed->subscribe($user, $request->request->getString('feed_url'));
                    } else {
                        $category = null;
                        if (!in_array($categoryChoice, ['none', 'new'], true)) {
                            try {
                                $category = $entityManager->getRepository(Category::class)->find(Uuid::fromString($categoryChoice));
                            } catch (\InvalidArgumentException) {
                                $category = null;
                            }
                            if (!$category instanceof Category || !$category->getUser()->getId()->equals($user->getId())) {
                                throw $this->createAccessDeniedException();
                            }
                        }
                        $entityManager->wrapInTransaction(function () use ($categoryChoice, $category, $createCategory, $subscribeToFeed, $user, $request): void {
                            $selected = 'new' === $categoryChoice ? $createCategory->create($user, $request->request->getString('new_category')) : $category;
                            $subscription = $subscribeToFeed->subscribe($user, $request->request->getString('feed_url'));
                            $subscription->assignCategory($selected);
                        });
                    }
                    $this->addFlash('success', 'Flux ajouté à vos abonnements.');
                }
            } catch (\InvalidArgumentException $exception) {
                $this->addFlash('error', $exception->getMessage());
            }

            return new RedirectResponse($this->generateUrl('app_subscriptions'), Response::HTTP_SEE_OTHER);
        }
        $stats = $subscriptions->articleStats($user);
        $latestPublications = array_map(static fn (array $row): mixed => $row['latestPublication'], $stats);
        $articleCounts = array_map(static fn (array $row): int => $row['articleCount'], $stats);

        return $this->render(
            'subscription/index.html.twig',
            [
                'latestPublications' => $latestPublications,
                'articleCounts' => $articleCounts,
                'subscriptions' => $entityManager->getRepository(Subscription::class)->findBy(['user' => $user], ['createdAt' => 'DESC']),
                'categories' => $entityManager->getRepository(Category::class)->findBy(['user' => $user], ['position' => 'ASC', 'name' => 'ASC']),
            ],
        );
    }

    #[Route('/subscriptions/refresh', name: 'app_subscriptions_refresh_start', methods: ['POST'])]
    public function refreshAll(Request $request, EntityManagerInterface $entityManager, FeedSyncRunService $syncRuns): Response
    {
        $user = $this->currentUser();
        $csrfToken = $request->headers->get('X-CSRF-Token') ?? $request->request->getString('_token');
        if (!$this->isCsrfTokenValid('subscriptions-refresh', $csrfToken)) {
            throw $this->createAccessDeniedException();
        }
        $feeds = array_map(
            static fn (Subscription $subscription): Uuid => $subscription->getFeed()->getId(),
            $entityManager->getRepository(Subscription::class)->findBy(['user' => $user]),
        );

        return $this->json(['runId' => (string) $syncRuns->start($feeds)], Response::HTTP_ACCEPTED);
    }

    #[Route('/subscriptions/refresh/{runId}', name: 'app_subscriptions_refresh_status', methods: ['GET'])]
    public function refreshAllStatus(string $runId, FeedSyncRunService $syncRuns): Response
    {
        $user = $this->currentUser();

        return $this->syncStatus($syncRuns->entries($this->runId($runId), user: $user->getId()));
    }

    #[Route('/subscriptions/{id}/category', name: 'app_subscription_move', methods: ['POST'])]
    public function moveCategory(string $id, Request $request, EntityManagerInterface $entityManager): RedirectResponse
    {
        $subscription = $this->ownedSubscription($id, $entityManager);
        if (!$this->isCsrfTokenValid('move-subscription-'.$id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $categoryId = $request->request->getString('category_id');
        $category = null;
        if ('' !== $categoryId) {
            try {
                $category = $entityManager->getRepository(Category::class)->find(Uuid::fromString($categoryId));
            } catch (\InvalidArgumentException) {
                $category = null;
            }
            if (!$category instanceof Category || !$category->getUser()->getId()->equals($this->currentUser()->getId())) {
                throw $this->createAccessDeniedException();
            }
        }
        $subscription->assignCategory($category);
        $entityManager->flush();
        $this->addFlash('success', 'Flux déplacé.');

        return $this->redirectToRoute('app_subscriptions', status: Response::HTTP_SEE_OTHER);
    }

    #[Route('/subscriptions/{id}/refresh', name: 'app_subscription_refresh_start', methods: ['POST'])]
    public function refresh(string $id, Request $request, EntityManagerInterface $entityManager, FeedSyncRunService $syncRuns): Response
    {
        $subscription = $this->ownedSubscription($id, $entityManager);
        $csrfToken = $request->headers->get('X-CSRF-Token') ?? $request->request->getString('_token');
        if (!$this->isCsrfTokenValid('subscription-refresh-'.$id, $csrfToken)) {
            throw $this->createAccessDeniedException();
        }

        return $this->json(['runId' => (string) $syncRuns->start([$subscription->getFeed()->getId()])], Response::HTTP_ACCEPTED);
    }

    #[Route('/subscriptions/{id}/refresh/{runId}', name: 'app_subscription_refresh_status', methods: ['GET'])]
    public function refreshStatus(string $id, string $runId, EntityManagerInterface $entityManager, FeedSyncRunService $syncRuns): Response
    {
        $subscription = $this->ownedSubscription($id, $entityManager);
        $rows = $syncRuns->entries($this->runId($runId), feed: $subscription->getFeed()->getId());
        if ([] === $rows) {
            throw $this->createNotFoundException();
        }

        return $this->syncStatus($rows);
    }

    #[Route('/categories/{id}/refresh', name: 'app_category_refresh_start', methods: ['POST'])]
    public function refreshCategory(string $id, Request $request, EntityManagerInterface $entityManager, FeedSyncRunService $syncRuns): Response
    {
        $category = $this->ownedCategory($id, $entityManager);
        $csrfToken = $request->headers->get('X-CSRF-Token') ?? $request->request->getString('_token');
        if (!$this->isCsrfTokenValid('category-refresh-'.$id, $csrfToken)) {
            throw $this->createAccessDeniedException();
        }
        $feeds = array_map(
            static fn (Subscription $subscription): Uuid => $subscription->getFeed()->getId(),
            $entityManager->getRepository(Subscription::class)->findBy(['user' => $this->currentUser(), 'category' => $category]),
        );

        return $this->json(['runId' => (string) $syncRuns->start($feeds)], Response::HTTP_ACCEPTED);
    }

    #[Route('/categories/{id}/refresh/{runId}', name: 'app_category_refresh_status', methods: ['GET'])]
    public function refreshCategoryStatus(string $id, string $runId, EntityManagerInterface $entityManager, FeedSyncRunService $syncRuns): Response
    {
        $category = $this->ownedCategory($id, $entityManager);

        return $this->syncStatus($syncRuns->entries($this->runId($runId), user: $this->currentUser()->getId(), category: $category->getId()));
    }

    #[Route('/subscriptions/{id}/update', name: 'app_subscription_update', methods: ['POST'])]
    public function update(string $id, Request $request, EntityManagerInterface $entityManager, SubscriptionEditor $editor): RedirectResponse
    {
        $subscription = $this->ownedSubscription($id, $entityManager);
        if (!$this->isCsrfTokenValid('subscription-'.$id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        $categoryId = $request->request->getString('category_id');
        if ('' !== $categoryId && !Uuid::isValid($categoryId)) {
            throw $this->createNotFoundException();
        }
        $category = '' === $categoryId ? null : $entityManager->getRepository(Category::class)->find(Uuid::fromString($categoryId));
        if (null !== $category && !$category->getUser()->getId()->equals($subscription->getUser()->getId())) {
            throw $this->createAccessDeniedException();
        }
        try {
            $editor->update(
                $subscription,
                $request->request->getString('feed_url', $subscription->getFeed()->getCanonicalUrl()),
                $category,
                $request->request->get('custom_title'),
                '1' === $request->request->getString('enabled'),
            );
        } catch (\InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());

            return $this->redirectToRoute('app_subscriptions', status: Response::HTTP_SEE_OTHER);
        }
        $this->addFlash('success', 'Abonnement mis à jour.');

        return $this->redirectToRoute('app_subscriptions', status: Response::HTTP_SEE_OTHER);
    }

    #[Route('/subscriptions/{id}/delete', name: 'app_subscription_delete', methods: ['POST'])]
    public function delete(string $id, Request $request, EntityManagerInterface $entityManager, SubscriptionRemoval $removal): RedirectResponse
    {
        $subscription = $this->ownedSubscription($id, $entityManager);
        if (!$this->isCsrfTokenValid('delete-subscription-'.$id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        $removal->remove($subscription);
        $this->addFlash('success', 'Source retirée de vos abonnements, avec vos états de lecture et favoris associés.');

        return $this->redirectToRoute('app_subscriptions', status: Response::HTTP_SEE_OTHER);
    }

    #[Route('/categories/{id}/update', name: 'app_category_update', methods: ['POST'])]
    public function updateCategory(string $id, Request $request, EntityManagerInterface $entityManager): RedirectResponse
    {
        $user = $this->currentUser();
        try {
            $category = $entityManager->getRepository(Category::class)->find(Uuid::fromString($id));
        } catch (\InvalidArgumentException) {
            $category = null;
        }
        if (!$category instanceof Category || !$category->getUser()->getId()->equals($user->getId())) {
            throw $this->createAccessDeniedException();
        }
        if (!$this->isCsrfTokenValid('update-category-'.$id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
        try {
            $name = trim($request->request->getString('category_name'));
            $existing = $entityManager->getRepository(Category::class)->findOneBy(['user' => $user, 'name' => $name]);
            if ($existing instanceof Category && !$existing->getId()->equals($category->getId())) {
                throw new \App\Application\Exception\CategoryAlreadyExists();
            }
            $category->rename($name);
            $entityManager->flush();
            $this->addFlash('success', 'Catégorie renommée.');
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_subscriptions', status: Response::HTTP_SEE_OTHER);
    }

    #[Route('/categories/{id}/delete', name: 'app_category_delete', methods: ['POST'])]
    public function deleteCategory(string $id, Request $request, EntityManagerInterface $entityManager, SubscriptionRemoval $removal): RedirectResponse
    {
        if (!Uuid::isValid($id)) {
            throw $this->createNotFoundException();
        }
        $user = $this->currentUser();
        $category = $entityManager->getRepository(Category::class)->find(Uuid::fromString($id));
        if (!$category instanceof Category || !$category->getUser()->getId()->equals($user->getId())) {
            throw $this->createAccessDeniedException();
        }
        if (!$this->isCsrfTokenValid('delete-category-'.$id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        $removal->removeCategory($user, $category);
        $this->addFlash('success', 'Catégorie et abonnements associés supprimés.');

        return $this->redirectToRoute('app_subscriptions', status: Response::HTTP_SEE_OTHER);
    }

    private function runId(string $runId): Uuid
    {
        return Uuid::isValid($runId) ? Uuid::fromString($runId) : throw $this->createNotFoundException();
    }

    /** @param list<array<string, mixed>> $rows */
    private function syncStatus(array $rows): Response
    {
        return $this->json(['entries' => $this->translateDetails($rows), 'complete' => FeedSyncRunService::isComplete($rows)]);
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    private function translateDetails(array $rows): array
    {
        return array_map(
            fn (array $row): array => [
                ...$row,
                'detail' => is_string($row['detail'] ?? null) ? $this->incidentDescriber->translate($row['detail']) : $row['detail'] ?? null,
            ],
            $rows,
        );
    }
}
