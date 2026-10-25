<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Subscription\SubscribeToFeed;
use App\Application\Subscription\CreateCategory;
use App\Application\Subscription\ImportOpml;
use App\Entity\Article;
use App\Entity\Category;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Message\CollectFeedMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

final class SubscriptionController extends AbstractController
{
    #[Route('/subscriptions', name: 'app_subscriptions', methods: ['GET', 'POST'])]
    public function index(Request $request, SubscribeToFeed $subscribeToFeed, CreateCategory $createCategory, ImportOpml $importOpml, EntityManagerInterface $entityManager, TranslatorInterface $translator): Response
    {
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
                        throw new \InvalidArgumentException('Veuillez sélectionner un fichier OPML valide.');
                    }
                    if (($file->getSize() ?? 0) > 2_000_000) {
                        throw new \InvalidArgumentException('Le fichier OPML ne doit pas dépasser 2 Mo.');
                    }
                    $xml = file_get_contents($file->getPathname());
                    if (false === $xml) {
                        throw new \InvalidArgumentException('Le fichier OPML ne peut pas être lu.');
                    }
                    $report = $importOpml->import($user, $xml);
                    $this->addFlash('success', $translator->trans('%imported% flux importé(s), %duplicate% déjà présent(s), %failed% non importé(s).', ['%imported%' => $report['imported'], '%duplicate%' => $report['duplicate'], '%failed%' => $report['failed']]));
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
                            try { $category = $entityManager->getRepository(Category::class)->find(Uuid::fromString($categoryChoice)); } catch (\InvalidArgumentException) { $category = null; }
                            if (!$category instanceof Category || !$category->getUser()->getId()->equals($user->getId())) throw $this->createAccessDeniedException();
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

        $latestArticles = $entityManager->createQueryBuilder()
            ->select('subscription.id AS subscriptionId, MAX(a.publishedAt) AS latestPublication, COUNT(a.id) AS articleCount')
            ->from(Article::class, 'a')
            ->innerJoin(Subscription::class, 'subscription', 'WITH', 'subscription.feed = a.feed AND subscription.user = :user')
            ->setParameter('user', $user->getId(), 'uuid')
            ->groupBy('subscription.id')
            ->getQuery()->getResult();
        $latestPublications = [];
        $articleCounts = [];
        foreach ($latestArticles as $row) {
            $subscriptionId = $row['subscriptionId'];
            if (is_string($subscriptionId) && 16 === strlen($subscriptionId)) {
                $subscriptionId = Uuid::fromBinary($subscriptionId);
            }
            $latestPublications[(string) $subscriptionId] = $row['latestPublication'];
            $articleCounts[(string) $subscriptionId] = (int) $row['articleCount'];
        }

        return $this->render('subscription/index.html.twig', [
            'latestPublications' => $latestPublications,
            'articleCounts' => $articleCounts,
            'subscriptions' => $entityManager->getRepository(Subscription::class)->findBy(['user' => $user], ['createdAt' => 'DESC']),
            'categories' => $entityManager->getRepository(Category::class)->findBy(['user' => $user], ['position' => 'ASC', 'name' => 'ASC']),
        ]);
    }

    #[Route('/subscriptions/refresh', name: 'app_subscriptions_refresh_start', methods: ['POST'])]
    public function refreshAll(Request $request, EntityManagerInterface $entityManager, MessageBusInterface $messageBus): Response
    {
        $user = $this->currentUser();
        $csrfToken = $request->headers->get('X-CSRF-Token') ?? $request->request->getString('_token');
        if (!$this->isCsrfTokenValid('subscriptions-refresh', $csrfToken)) throw $this->createAccessDeniedException();

        $run = Uuid::v7();
        $connection = $entityManager->getConnection();
        $connection->insert('feed_sync_run', ['id' => $run->toBinary(), 'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s')]);
        foreach ($entityManager->getRepository(Subscription::class)->findBy(['user' => $user]) as $subscription) {
            $connection->insert('feed_sync_run_entry', ['run_id' => $run->toBinary(), 'feed_id' => $subscription->getFeed()->getId()->toBinary(), 'status' => 'pending', 'new_articles' => 0]);
            $messageBus->dispatch(new CollectFeedMessage((string) $subscription->getFeed()->getId(), (string) $run));
        }

        return $this->json(['runId' => (string) $run], Response::HTTP_ACCEPTED);
    }

    #[Route('/subscriptions/refresh/{runId}', name: 'app_subscriptions_refresh_status', methods: ['GET'])]
    public function refreshAllStatus(string $runId, EntityManagerInterface $entityManager): Response
    {
        $user = $this->currentUser();
        try { $run = Uuid::fromString($runId); } catch (\InvalidArgumentException) { throw $this->createNotFoundException(); }

        $rows = $entityManager->getConnection()->fetchAllAssociative(
            'SELECT f.title, f.canonical_url, e.status, e.new_articles, e.detail FROM feed_sync_run_entry e INNER JOIN feed f ON f.id = e.feed_id INNER JOIN subscription s ON s.feed_id = f.id WHERE e.run_id = ? AND s.user_id = ? ORDER BY f.title, f.canonical_url',
            [$run->toBinary(), $user->getId()->toBinary()],
        );

        return $this->json(['entries' => $rows, 'complete' => !array_filter($rows, static fn (array $row): bool => in_array($row['status'], ['pending', 'running'], true))]);
    }

    #[Route('/subscriptions/{id}/category', name: 'app_subscription_move', methods: ['POST'])]
    public function moveCategory(string $id, Request $request, EntityManagerInterface $entityManager): RedirectResponse
    {
        $subscription = $this->ownedSubscription($id, $entityManager);
        if (!$this->isCsrfTokenValid('move-subscription-'.$id, $request->request->getString('_token'))) throw $this->createAccessDeniedException();
        $categoryId = $request->request->getString('category_id');
        $category = null;
        if ('' !== $categoryId) {
            try { $category = $entityManager->getRepository(Category::class)->find(Uuid::fromString($categoryId)); } catch (\InvalidArgumentException) { $category = null; }
            if (!$category instanceof Category || !$category->getUser()->getId()->equals($this->currentUser()->getId())) throw $this->createAccessDeniedException();
        }
        $subscription->assignCategory($category);
        $entityManager->flush();
        $this->addFlash('success', 'Flux déplacé.');
        return $this->redirectToRoute('app_subscriptions', status: Response::HTTP_SEE_OTHER);
    }

    #[Route('/subscriptions/{id}/refresh', name: 'app_subscription_refresh_start', methods: ['POST'])]
    public function refresh(string $id, Request $request, EntityManagerInterface $entityManager, MessageBusInterface $messageBus): Response
    {
        $subscription = $this->ownedSubscription($id, $entityManager);
        $csrfToken = $request->headers->get('X-CSRF-Token') ?? $request->request->getString('_token');
        if (!$this->isCsrfTokenValid('subscription-refresh-'.$id, $csrfToken)) throw $this->createAccessDeniedException();

        $run = Uuid::v7();
        $connection = $entityManager->getConnection();
        $connection->insert('feed_sync_run', ['id' => $run->toBinary(), 'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s')]);
        $connection->insert('feed_sync_run_entry', ['run_id' => $run->toBinary(), 'feed_id' => $subscription->getFeed()->getId()->toBinary(), 'status' => 'pending', 'new_articles' => 0]);
        $messageBus->dispatch(new CollectFeedMessage((string) $subscription->getFeed()->getId(), (string) $run));

        return $this->json(['runId' => (string) $run], Response::HTTP_ACCEPTED);
    }

    #[Route('/subscriptions/{id}/refresh/{runId}', name: 'app_subscription_refresh_status', methods: ['GET'])]
    public function refreshStatus(string $id, string $runId, EntityManagerInterface $entityManager): Response
    {
        $subscription = $this->ownedSubscription($id, $entityManager);
        try { $run = Uuid::fromString($runId); } catch (\InvalidArgumentException) { throw $this->createNotFoundException(); }

        $rows = $entityManager->getConnection()->fetchAllAssociative(
            'SELECT f.title, f.canonical_url, e.status, e.new_articles, e.detail FROM feed_sync_run_entry e INNER JOIN feed f ON f.id = e.feed_id WHERE e.run_id = ? AND e.feed_id = ?',
            [$run->toBinary(), $subscription->getFeed()->getId()->toBinary()],
        );
        if ([] === $rows) throw $this->createNotFoundException();

        return $this->json(['entries' => $rows, 'complete' => !array_filter($rows, static fn (array $row): bool => in_array($row['status'], ['pending', 'running'], true))]);
    }

    #[Route('/categories/{id}/refresh', name: 'app_category_refresh_start', methods: ['POST'])]
    public function refreshCategory(string $id, Request $request, EntityManagerInterface $entityManager, MessageBusInterface $messageBus): Response
    {
        $category = $this->ownedCategory($id, $entityManager);
        $csrfToken = $request->headers->get('X-CSRF-Token') ?? $request->request->getString('_token');
        if (!$this->isCsrfTokenValid('category-refresh-'.$id, $csrfToken)) throw $this->createAccessDeniedException();

        $run = Uuid::v7();
        $connection = $entityManager->getConnection();
        $connection->insert('feed_sync_run', ['id' => $run->toBinary(), 'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s')]);
        foreach ($entityManager->getRepository(Subscription::class)->findBy(['user' => $this->currentUser(), 'category' => $category]) as $subscription) {
            $connection->insert('feed_sync_run_entry', ['run_id' => $run->toBinary(), 'feed_id' => $subscription->getFeed()->getId()->toBinary(), 'status' => 'pending', 'new_articles' => 0]);
            $messageBus->dispatch(new CollectFeedMessage((string) $subscription->getFeed()->getId(), (string) $run));
        }

        return $this->json(['runId' => (string) $run], Response::HTTP_ACCEPTED);
    }

    #[Route('/categories/{id}/refresh/{runId}', name: 'app_category_refresh_status', methods: ['GET'])]
    public function refreshCategoryStatus(string $id, string $runId, EntityManagerInterface $entityManager): Response
    {
        $category = $this->ownedCategory($id, $entityManager);
        try { $run = Uuid::fromString($runId); } catch (\InvalidArgumentException) { throw $this->createNotFoundException(); }

        $rows = $entityManager->getConnection()->fetchAllAssociative(
            'SELECT f.title, f.canonical_url, e.status, e.new_articles, e.detail FROM feed_sync_run_entry e INNER JOIN feed f ON f.id = e.feed_id INNER JOIN subscription s ON s.feed_id = f.id WHERE e.run_id = ? AND s.user_id = ? AND s.category_id = ? ORDER BY f.title, f.canonical_url',
            [$run->toBinary(), $this->currentUser()->getId()->toBinary(), $category->getId()->toBinary()],
        );

        return $this->json(['entries' => $rows, 'complete' => !array_filter($rows, static fn (array $row): bool => in_array($row['status'], ['pending', 'running'], true))]);
    }

    #[Route('/subscriptions/{id}/update', name: 'app_subscription_update', methods: ['POST'])]
    public function update(string $id, Request $request, EntityManagerInterface $entityManager): RedirectResponse
    {
        $subscription = $this->ownedSubscription($id, $entityManager);
        if (!$this->isCsrfTokenValid('subscription-'.$id, $request->request->getString('_token'))) throw $this->createAccessDeniedException('Invalid CSRF token.');
        $categoryId = $request->request->getString('category_id');
        $category = '' === $categoryId ? null : $entityManager->getRepository(Category::class)->find(Uuid::fromString($categoryId));
        if (null !== $category && $category->getUser()->getId()->equals($subscription->getUser()->getId()) === false) throw $this->createAccessDeniedException();
        try {
            $url = trim($request->request->getString('feed_url', $subscription->getFeed()->getCanonicalUrl()));
            $parts = parse_url($url);
            if (strlen($url) > 2048 || !filter_var($url, FILTER_VALIDATE_URL) || !is_array($parts) || isset($parts['user']) || isset($parts['pass'])) {
                throw new \InvalidArgumentException('Saisissez une adresse de flux HTTP ou HTTPS valide, sans identifiants.');
            }
            $candidate = new Feed($url);
            $feed = $entityManager->getRepository(Feed::class)->findOneBy(['canonicalUrlHash' => $candidate->getCanonicalUrlHash()]);
            if (!$feed instanceof Feed) $feed = $candidate;
            $existing = $feed === $candidate ? null : $entityManager->getRepository(Subscription::class)->findOneBy(['user' => $subscription->getUser(), 'feed' => $feed]);
            if ($existing instanceof Subscription && !$existing->getId()->equals($subscription->getId())) {
                throw new \InvalidArgumentException('Vous êtes déjà abonné à ce flux.');
            }
            $subscription->assignCategory($category);
            $subscription->rename($request->request->get('custom_title'));
            if ($feed === $candidate) $entityManager->persist($feed);
            $subscription->changeFeed($feed);
            '1' === $request->request->getString('enabled') ? $subscription->enable() : $subscription->disable();
            $entityManager->flush();
        } catch (\InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());
            return $this->redirectToRoute('app_subscriptions', status: Response::HTTP_SEE_OTHER);
        }
        $this->addFlash('success', 'Abonnement mis à jour.'); return $this->redirectToRoute('app_subscriptions', status: Response::HTTP_SEE_OTHER);
    }

    #[Route('/subscriptions/{id}/delete', name: 'app_subscription_delete', methods: ['POST'])]
    public function delete(string $id, Request $request, EntityManagerInterface $entityManager): RedirectResponse
    {
        $subscription = $this->ownedSubscription($id, $entityManager);
        if (!$this->isCsrfTokenValid('delete-subscription-'.$id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $entityManager->wrapInTransaction(function () use ($entityManager, $subscription): void {
            $entityManager->getConnection()->executeStatement(
                'DELETE article_state FROM article_state INNER JOIN article ON article.id = article_state.article_id WHERE article_state.user_id = ? AND article.feed_id = ?',
                [$subscription->getUser()->getId()->toBinary(), $subscription->getFeed()->getId()->toBinary()],
                [\Doctrine\DBAL\ParameterType::BINARY, \Doctrine\DBAL\ParameterType::BINARY],
            );
            $entityManager->remove($subscription);
            $entityManager->flush();
        });

        $this->addFlash('success', 'Source retirée de vos abonnements, avec vos états de lecture et favoris associés.');

        return $this->redirectToRoute('app_subscriptions', status: Response::HTTP_SEE_OTHER);
    }

    #[Route('/categories/{id}/update', name: 'app_category_update', methods: ['POST'])]
    public function updateCategory(string $id, Request $request, EntityManagerInterface $entityManager): RedirectResponse
    {
        $user = $this->currentUser();
        try { $category = $entityManager->getRepository(Category::class)->find(Uuid::fromString($id)); } catch (\InvalidArgumentException) { $category = null; }
        if (!$category instanceof Category || !$category->getUser()->getId()->equals($user->getId())) throw $this->createAccessDeniedException();
        if (!$this->isCsrfTokenValid('update-category-'.$id, $request->request->getString('_token'))) throw $this->createAccessDeniedException();
        try {
            $name = trim($request->request->getString('category_name'));
            $existing = $entityManager->getRepository(Category::class)->findOneBy(['user' => $user, 'name' => $name]);
            if ($existing instanceof Category && !$existing->getId()->equals($category->getId())) throw new \InvalidArgumentException('Cette catégorie existe déjà.');
            $category->rename($name);
            $entityManager->flush();
            $this->addFlash('success', 'Catégorie renommée.');
        } catch (\InvalidArgumentException $e) { $this->addFlash('error', $e->getMessage()); }
        return $this->redirectToRoute('app_subscriptions', status: Response::HTTP_SEE_OTHER);
    }

    #[Route('/categories/{id}/delete', name: 'app_category_delete', methods: ['POST'])]
    public function deleteCategory(string $id, Request $request, EntityManagerInterface $entityManager): RedirectResponse
    {
        $user = $this->currentUser(); $category = $entityManager->getRepository(Category::class)->find(Uuid::fromString($id));
        if (!$category instanceof Category || !$category->getUser()->getId()->equals($user->getId())) throw $this->createAccessDeniedException();
        if (!$this->isCsrfTokenValid('delete-category-'.$id, $request->request->getString('_token'))) throw $this->createAccessDeniedException('Invalid CSRF token.');
        $entityManager->wrapInTransaction(function () use ($entityManager, $user, $category): void {
            foreach ($entityManager->getRepository(Subscription::class)->findBy(['user' => $user, 'category' => $category]) as $subscription) {
                $entityManager->getConnection()->executeStatement('DELETE article_state FROM article_state INNER JOIN article ON article.id = article_state.article_id WHERE article_state.user_id = ? AND article.feed_id = ?', [$user->getId()->toBinary(), $subscription->getFeed()->getId()->toBinary()], [\Doctrine\DBAL\ParameterType::BINARY, \Doctrine\DBAL\ParameterType::BINARY]);
                $entityManager->remove($subscription);
            }
            $entityManager->remove($category);
            $entityManager->flush();
        });
        $this->addFlash('success', 'Catégorie et abonnements associés supprimés.');
        return $this->redirectToRoute('app_subscriptions', status: Response::HTTP_SEE_OTHER);
    }

    private function currentUser(): User
    {
        $user = $this->getUser(); if (!$user instanceof User) throw $this->createAccessDeniedException(); return $user;
    }

    private function ownedSubscription(string $id, EntityManagerInterface $entityManager): Subscription
    {
        try { $subscription = $entityManager->getRepository(Subscription::class)->find(Uuid::fromString($id)); } catch (\InvalidArgumentException) { $subscription = null; }
        if (!$subscription instanceof Subscription || !$subscription->getUser()->getId()->equals($this->currentUser()->getId())) throw $this->createAccessDeniedException();
        return $subscription;
    }

    private function ownedCategory(string $id, EntityManagerInterface $entityManager): Category
    {
        try { $category = $entityManager->getRepository(Category::class)->find(Uuid::fromString($id)); } catch (\InvalidArgumentException) { $category = null; }
        if (!$category instanceof Category || !$category->getUser()->getId()->equals($this->currentUser()->getId())) throw $this->createAccessDeniedException();
        return $category;
    }
}
