<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Subscription\SubscribeToFeed;
use App\Application\Subscription\CreateCategory;
use App\Entity\Category;
use App\Entity\Subscription;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SubscriptionController extends AbstractController
{
    #[Route('/subscriptions', name: 'app_subscriptions', methods: ['GET', 'POST'])]
    public function index(Request $request, SubscribeToFeed $subscribeToFeed, CreateCategory $createCategory, EntityManagerInterface $entityManager): Response
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

        return $this->render('subscription/index.html.twig', [
            'subscriptions' => $entityManager->getRepository(Subscription::class)->findBy(['user' => $user], ['createdAt' => 'DESC']),
            'categories' => $entityManager->getRepository(Category::class)->findBy(['user' => $user], ['position' => 'ASC', 'name' => 'ASC']),
        ]);
    }
}
