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
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

final class SubscriptionController extends AbstractController
{
    #[Route('/subscriptions', name: 'app_subscriptions', methods: ['GET', 'POST'])]
    public function index(Request $request, SubscribeToFeed $subscribeToFeed, CreateCategory $createCategory, ImportOpml $importOpml, EntityManagerInterface $entityManager): Response
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
                    $this->addFlash('success', sprintf('%d flux importé(s), %d déjà présent(s), %d non importé(s).', $report['imported'], $report['duplicate'], $report['failed']));
                } else {
                    if (!$this->isCsrfTokenValid('subscribe-feed', $request->request->getString('_token'))) {
                        throw $this->createAccessDeniedException('Invalid CSRF token.');
                    }

                    $subscribeToFeed->subscribe($user, $request->request->getString('feed_url'));
                    $this->addFlash('success', 'Flux ajouté à vos abonnements.');
                }
            } catch (\InvalidArgumentException $exception) {
                $this->addFlash('error', $exception->getMessage());
            }

            return new RedirectResponse($this->generateUrl('app_subscriptions'), Response::HTTP_SEE_OTHER);
        }

        $latestArticles = $entityManager->createQueryBuilder()
            ->select('subscription.id AS subscriptionId, MAX(a.publishedAt) AS latestPublication')
            ->from(Article::class, 'a')
            ->innerJoin(Subscription::class, 'subscription', 'WITH', 'subscription.feed = a.feed AND subscription.user = :user')
            ->setParameter('user', $user->getId(), 'uuid')
            ->groupBy('subscription.id')
            ->getQuery()->getResult();
        $latestPublications = [];
        foreach ($latestArticles as $row) {
            $subscriptionId = $row['subscriptionId'];
            if (is_string($subscriptionId) && 16 === strlen($subscriptionId)) {
                $subscriptionId = Uuid::fromBinary($subscriptionId);
            }
            $latestPublications[(string) $subscriptionId] = $row['latestPublication'];
        }

        return $this->render('subscription/index.html.twig', [
            'latestPublications' => $latestPublications,
            'subscriptions' => $entityManager->getRepository(Subscription::class)->findBy(['user' => $user], ['createdAt' => 'DESC']),
            'categories' => $entityManager->getRepository(Category::class)->findBy(['user' => $user], ['position' => 'ASC', 'name' => 'ASC']),
        ]);
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

    #[Route('/categories/{id}/delete', name: 'app_category_delete', methods: ['POST'])]
    public function deleteCategory(string $id, Request $request, EntityManagerInterface $entityManager): RedirectResponse
    {
        $user = $this->currentUser(); $category = $entityManager->getRepository(Category::class)->find(Uuid::fromString($id));
        if (!$category instanceof Category || !$category->getUser()->getId()->equals($user->getId())) throw $this->createAccessDeniedException();
        if (!$this->isCsrfTokenValid('delete-category-'.$id, $request->request->getString('_token'))) throw $this->createAccessDeniedException('Invalid CSRF token.');
        foreach ($entityManager->getRepository(Subscription::class)->findBy(['user' => $user, 'category' => $category]) as $subscription) $subscription->assignCategory(null);
        $entityManager->remove($category); $entityManager->flush(); $this->addFlash('success', 'Catégorie supprimée ; les abonnements sont sans catégorie.');
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
}
