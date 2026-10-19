<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Reading\ArticleStateService;
use App\Entity\Article;
use App\Entity\ArticleState;
use App\Entity\Subscription;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

final class ArticleController extends AbstractController
{
    private const PAGE_SIZE = 30;

    #[Route('/articles', name: 'app_articles', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('article/index.html.twig');
    }

    #[Route('/api/articles', name: 'app_api_articles', methods: ['GET'])]
    public function list(Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        $user = $this->currentUser();
        $cursor = $this->decodeCursor($request->query->getString('cursor'));
        $query = $entityManager->createQueryBuilder()
            ->select('a.id AS id, a.title AS title, a.url AS url, a.summary AS summary, a.publishedAt AS publishedAt, a.collectedAt AS collectedAt, f.title AS source, s.isRead AS isRead, s.isFavorite AS isFavorite, s.isSaved AS isSaved')
            ->from(Article::class, 'a')
            ->innerJoin('a.feed', 'f')
            ->innerJoin(Subscription::class, 'subscription', 'WITH', 'subscription.feed = f AND subscription.user = :user AND subscription.enabled = true')
            ->leftJoin(ArticleState::class, 's', 'WITH', 's.article = a AND s.user = :user')
            ->setParameter('user', $user)
            ->orderBy('a.collectedAt', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->setMaxResults(self::PAGE_SIZE + 1);

        if (null !== $cursor) {
            $query
                ->andWhere('a.collectedAt < :cursorDate OR (a.collectedAt = :cursorDate AND a.id < :cursorId)')
                ->setParameter('cursorDate', new \DateTimeImmutable($cursor['collectedAt']))
                ->setParameter('cursorId', Uuid::fromString($cursor['id']));
        }

        $items = $query->getQuery()->getArrayResult();
        $hasMore = count($items) > self::PAGE_SIZE;
        if ($hasMore) {
            array_pop($items);
        }

        $nextCursor = null;
        if ($hasMore && [] !== $items) {
            $last = $items[array_key_last($items)];
            $nextCursor = $this->encodeCursor($last['collectedAt']->format(DATE_ATOM), (string) $last['id']);
        }

        return $this->json([
            'items' => array_map(fn (array $item): array => $this->serializeListItem($item), $items),
            'nextCursor' => $nextCursor,
            'hasMore' => $hasMore,
        ]);
    }

    #[Route('/api/articles/{id}', name: 'app_api_article_show', methods: ['GET'])]
    public function show(string $id, EntityManagerInterface $entityManager): JsonResponse
    {
        $article = $this->accessibleArticle($id, $entityManager);
        $state = $entityManager->getRepository(ArticleState::class)->findOneBy(['user' => $this->currentUser(), 'article' => $article]);

        return $this->json([
            'id' => (string) $article->getId(),
            'title' => $article->getTitle(),
            'url' => $article->getUrl(),
            'summary' => $article->getSummary(),
            'content' => $article->getContent(),
            'publishedAt' => $article->getPublishedAt()?->format(DATE_ATOM),
            'source' => $article->getFeed()->getTitle() ?? $article->getFeed()->getCanonicalUrl(),
            'isRead' => $state?->isRead() ?? false,
            'isFavorite' => $state?->isFavorite() ?? false,
            'isSaved' => $state?->isSaved() ?? false,
            'csrfToken' => $this->container->get('security.csrf.token_manager')->getToken('article-state-'.$article->getId())->getValue(),
        ]);
    }

    #[Route('/api/articles/{id}/state', name: 'app_api_article_state', methods: ['PATCH'])]
    public function updateState(string $id, Request $request, EntityManagerInterface $entityManager, ArticleStateService $articleStateService): JsonResponse
    {
        $article = $this->accessibleArticle($id, $entityManager);
        if (!$this->isCsrfTokenValid('article-state-'.$article->getId(), $request->headers->get('X-CSRF-Token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        try {
            $payload = json_decode($request->getContent(), true, 32, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->json(['error' => 'JSON invalide.'], Response::HTTP_BAD_REQUEST);
        }

        $changes = array_intersect_key($payload, array_flip(['isRead', 'isFavorite', 'isSaved']));
        if ([] === $changes || array_filter($changes, static fn (mixed $value): bool => !is_bool($value)) !== []) {
            return $this->json(['error' => 'État invalide.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $state = $articleStateService->update($this->currentUser(), $article, $changes);

        return $this->json(['isRead' => $state->isRead(), 'isFavorite' => $state->isFavorite(), 'isSaved' => $state->isSaved()]);
    }

    #[Route('/api/articles/bulk', name: 'app_api_articles_bulk', methods: ['POST'])]
    public function bulk(Request $request, EntityManagerInterface $entityManager, ArticleStateService $articleStateService): JsonResponse
    {
        if (!$this->isCsrfTokenValid('article-bulk', $request->headers->get('X-CSRF-Token'))) { throw $this->createAccessDeniedException(); }
        try { $payload = json_decode($request->getContent(), true, 32, \JSON_THROW_ON_ERROR); } catch (\JsonException) { return $this->json(['error' => 'JSON invalide.'], 400); }
        $ids = $payload['ids'] ?? null;
        $changes = is_array($payload) ? array_intersect_key($payload, array_flip(['isRead', 'isFavorite', 'isSaved'])) : [];
        if (!is_array($ids) || [] === $ids || count($ids) > 100 || [] === $changes || array_filter($changes, static fn (mixed $value): bool => !is_bool($value)) !== []) return $this->json(['error' => 'Action invalide.'], 422);
        $changed = 0;
        foreach ($ids as $id) {
            if (!is_string($id)) continue;
            try { $articleStateService->update($this->currentUser(), $this->accessibleArticle($id, $entityManager), $changes); ++$changed; } catch (\DomainException|\Symfony\Component\HttpKernel\Exception\NotFoundHttpException) { }
        }

        return $this->json(['matched' => count($ids), 'changed' => $changed, 'skipped' => count($ids) - $changed]);
    }

    #[Route('/api/articles/mark-before', name: 'app_api_articles_mark_before', methods: ['POST'])]
    public function markBefore(Request $request, EntityManagerInterface $entityManager, ArticleStateService $articleStateService): JsonResponse
    {
        if (!$this->isCsrfTokenValid('article-bulk', $request->headers->get('X-CSRF-Token'))) { throw $this->createAccessDeniedException(); }
        try { $payload = json_decode($request->getContent(), true, 16, \JSON_THROW_ON_ERROR); $before = new \DateTimeImmutable((string) ($payload['before'] ?? '')); } catch (\Exception) { return $this->json(['error' => 'Date invalide.'], 422); }
        $articles = $entityManager->createQueryBuilder()->select('a')->from(Article::class, 'a')->innerJoin(Subscription::class, 'subscription', 'WITH', 'subscription.feed = a.feed AND subscription.user = :user AND subscription.enabled = true')->where('COALESCE(a.publishedAt, a.collectedAt) < :before')->setParameter('user', $this->currentUser())->setParameter('before', $before)->setMaxResults(500)->getQuery()->getResult();
        foreach ($articles as $article) { $articleStateService->update($this->currentUser(), $article, ['isRead' => true]); }

        return $this->json(['matched' => count($articles), 'changed' => count($articles), 'skipped' => 0]);
    }

    private function accessibleArticle(string $id, EntityManagerInterface $entityManager): Article
    {
        try { $articleId = Uuid::fromString($id); } catch (\InvalidArgumentException) { throw $this->createNotFoundException(); }
        $article = $entityManager->createQueryBuilder()
            ->select('a')
            ->from(Article::class, 'a')
            ->innerJoin(Subscription::class, 'subscription', 'WITH', 'subscription.feed = a.feed AND subscription.user = :user AND subscription.enabled = true')
            ->where('a.id = :id')
            ->setParameter('user', $this->currentUser())
            ->setParameter('id', $articleId)
            ->getQuery()->getOneOrNullResult();
        if (!$article instanceof Article) { throw $this->createNotFoundException(); }

        return $article;
    }

    /** @return array{collectedAt: string, id: string}|null */
    private function decodeCursor(string $cursor): ?array
    {
        if ('' === $cursor) { return null; }
        $decoded = base64_decode(strtr($cursor, '-_', '+/').str_repeat('=', (4 - strlen($cursor) % 4) % 4), true);
        if (false === $decoded) { throw $this->createNotFoundException(); }
        try { $value = json_decode($decoded, true, 4, \JSON_THROW_ON_ERROR); } catch (\JsonException) { throw $this->createNotFoundException(); }
        if (!is_array($value) || !isset($value['collectedAt'], $value['id']) || !is_string($value['collectedAt']) || !is_string($value['id'])) { throw $this->createNotFoundException(); }
        try { new \DateTimeImmutable($value['collectedAt']); Uuid::fromString($value['id']); } catch (\Exception) { throw $this->createNotFoundException(); }

        return $value;
    }

    private function encodeCursor(string $collectedAt, string $id): string
    {
        return rtrim(strtr(base64_encode(json_encode(['collectedAt' => $collectedAt, 'id' => $id], \JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    /** @param array<string, mixed> $item @return array<string, mixed> */
    private function serializeListItem(array $item): array
    {
        return ['id' => (string) $item['id'], 'title' => $item['title'], 'url' => $item['url'], 'summary' => $item['summary'], 'source' => $item['source'], 'publishedAt' => $item['publishedAt']?->format(DATE_ATOM), 'collectedAt' => $item['collectedAt']->format(DATE_ATOM), 'isRead' => (bool) ($item['isRead'] ?? false), 'isFavorite' => (bool) ($item['isFavorite'] ?? false), 'isSaved' => (bool) ($item['isSaved'] ?? false)];
    }

    private function currentUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) { throw $this->createAccessDeniedException(); }

        return $user;
    }
}
