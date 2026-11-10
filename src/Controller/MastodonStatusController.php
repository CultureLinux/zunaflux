<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Mastodon\MastodonOAuthService;
use App\Application\Mastodon\MastodonApiException;
use App\Entity\Article;
use App\Entity\MastodonAccount;
use App\Entity\Subscription;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

final class MastodonStatusController extends AbstractController
{
    #[Route('/api/articles/{id}/mastodon-status', name: 'app_api_article_mastodon_status', methods: ['POST'])]
    public function publish(string $id, Request $request, EntityManagerInterface $entityManager, MastodonOAuthService $mastodon): JsonResponse
    {
        $user = $this->currentUser();
        $article = $this->accessibleArticle($id, $entityManager, $user);
        if (!$this->isCsrfTokenValid('mastodon-status-'.$article->getId(), $request->headers->get('X-CSRF-Token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }
        try {
            $payload = json_decode($request->getContent(), true, 32, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->json(['error' => 'JSON invalide.'], Response::HTTP_BAD_REQUEST);
        }
        if (!is_array($payload) || !isset($payload['status'], $payload['visibility']) || !is_string($payload['status']) || !is_string($payload['visibility'])) {
            return $this->json(['error' => 'Publication Mastodon invalide.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $account = $entityManager->getRepository(MastodonAccount::class)->findOneBy(['user' => $user]);
        if (!$account instanceof MastodonAccount) {
            return $this->json(['error' => 'Connectez d’abord votre compte Mastodon.'], Response::HTTP_CONFLICT);
        }
        try {
            return $this->json(['url' => $mastodon->publish($account, $payload['status'], $payload['visibility'])]);
        } catch (\InvalidArgumentException $exception) {
            return $this->json(['error' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (MastodonApiException $exception) {
            if (Response::HTTP_UNAUTHORIZED === $exception->getStatusCode()) {
                $entityManager->remove($account);
                $entityManager->flush();

                return $this->json(['error' => 'Votre connexion Mastodon a expiré. Reconnectez votre compte.'], Response::HTTP_CONFLICT);
            }

            return $this->json(['error' => 'Mastodon a refusé la publication.'], Response::HTTP_BAD_GATEWAY);
        } catch (\RuntimeException $exception) {
            return $this->json(['error' => $exception->getMessage()], Response::HTTP_BAD_GATEWAY);
        }
    }

    private function accessibleArticle(string $id, EntityManagerInterface $entityManager, User $user): Article
    {
        try {
            $articleId = Uuid::fromString($id);
        } catch (\InvalidArgumentException) {
            throw $this->createNotFoundException();
        }
        $article = $entityManager->createQueryBuilder()
            ->select('a')
            ->from(Article::class, 'a')
            ->innerJoin(Subscription::class, 'subscription', 'WITH', 'subscription.feed = a.feed AND subscription.user = :user AND subscription.enabled = true')
            ->where('a.id = :id')
            ->setParameter('user', $user->getId(), 'uuid')
            ->setParameter('id', $articleId, 'uuid')
            ->getQuery()
            ->getOneOrNullResult();
        if (!$article instanceof Article) {
            throw $this->createNotFoundException();
        }

        return $article;
    }

    private function currentUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
