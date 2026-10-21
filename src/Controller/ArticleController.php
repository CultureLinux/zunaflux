<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Reading\ArticleStateService;
use App\Application\Reading\FeedHtmlSanitizer;
use App\Entity\Article;
use App\Entity\ArticleState;
use App\Entity\Subscription;
use App\Entity\User;
use App\Entity\UserPreference;
use App\Infrastructure\Reading\FeedImageFetcher;
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
    public function index(EntityManagerInterface $entityManager): Response
    {
        $user = $this->currentUser();
        $preference = $entityManager->getRepository(UserPreference::class)->findOneBy(['user' => $user]);
        if (!$preference instanceof UserPreference) {
            $preference = new UserPreference($user);
            $entityManager->persist($preference);
            $entityManager->flush();
        }
        $subscriptions = $preference->isSidebarCollapsed()
            ? $entityManager->getRepository(Subscription::class)->findBy(['user' => $user, 'enabled' => true])
            : [];
        $readingCounts = [];
        if ($preference->isSidebarCollapsed()) {
            $counts = $entityManager->getConnection()->fetchAllAssociative('SELECT s.id, COUNT(a.id) AS total, COALESCE(SUM(CASE WHEN a.id IS NOT NULL AND COALESCE(st.is_read, 0) = 0 THEN 1 ELSE 0 END), 0) AS unread FROM subscription s LEFT JOIN article a ON a.feed_id = s.feed_id LEFT JOIN article_state st ON st.article_id = a.id AND st.user_id = s.user_id WHERE s.user_id = ? AND s.enabled = 1 GROUP BY s.id', [$user->getId()->toBinary()], [\Doctrine\DBAL\ParameterType::BINARY]);
            foreach ($counts as $count) $readingCounts[(string) Uuid::fromBinary($count['id'])] = ['unread' => (int) $count['unread'], 'read' => (int) $count['total'] - (int) $count['unread']];
        }

        return $this->render('article/index.html.twig', ['preference' => $preference, 'readingSubscriptions' => $subscriptions, 'readingCounts' => $readingCounts]);
    }

    #[Route('/subscriptions/{id}/mark-read', name: 'app_subscription_mark_read', methods: ['POST'])]
    public function markSubscriptionRead(string $id, Request $request, EntityManagerInterface $entityManager, ArticleStateService $stateService): Response
    {
        try { $uuid = Uuid::fromString($id); } catch (\InvalidArgumentException) { throw $this->createNotFoundException(); }
        $user = $this->currentUser();
        $subscription = $entityManager->getRepository(Subscription::class)->findOneBy(['id' => $uuid, 'user' => $user, 'enabled' => true]);
        if (!$subscription instanceof Subscription) throw $this->createNotFoundException();
        if (!$this->isCsrfTokenValid('mark-feed-read-'.$id, $request->request->getString('_token'))) throw $this->createAccessDeniedException();
        $cursor = null;
        do {
            $query = $entityManager->createQueryBuilder()->select('a')->from(Article::class, 'a')->leftJoin(ArticleState::class, 's', 'WITH', 's.article = a AND s.user = :user')->where('a.feed = :feed')->andWhere('s.id IS NULL OR s.isRead = false')->setParameter('user', $user->getId(), 'uuid')->setParameter('feed', $subscription->getFeed()->getId(), 'uuid')->orderBy('a.id', 'ASC')->setMaxResults(100);
            if ($cursor !== null) $query->andWhere('a.id > :cursor')->setParameter('cursor', $cursor, 'uuid');
            $articles = $query->getQuery()->getResult();
            foreach ($articles as $article) { $stateService->update($user, $article, ['isRead' => true]); $cursor = $article->getId(); }
        } while (count($articles) === 100);

        return $this->redirectToRoute('app_articles', ['subscription' => $id, 'read' => '0']);
    }

    #[Route('/api/articles', name: 'app_api_articles', methods: ['GET'])]
    public function list(Request $request, EntityManagerInterface $entityManager, FeedHtmlSanitizer $sanitizer): JsonResponse
    {
        $user = $this->currentUser();
        $cursor = $this->decodeCursor($request->query->getString('cursor'));
        $query = $entityManager->createQueryBuilder()
            ->select('a.id AS id, a.title AS title, a.url AS url, a.summary AS summary, a.content AS content, a.publishedAt AS publishedAt, a.collectedAt AS collectedAt, f.title AS source, subscription.id AS subscriptionId, subscription.customTitle AS subscriptionTitle, s.isRead AS isRead, s.isFavorite AS isFavorite, s.isSaved AS isSaved')
            ->from(Article::class, 'a')
            ->innerJoin('a.feed', 'f')
            ->innerJoin(Subscription::class, 'subscription', 'WITH', 'subscription.feed = f AND subscription.user = :user AND subscription.enabled = true')
            ->leftJoin(ArticleState::class, 's', 'WITH', 's.article = a AND s.user = :user')
            ->setParameter('user', $user->getId(), 'uuid')
            ->orderBy('a.collectedAt', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->setMaxResults(self::PAGE_SIZE + 1);

        if (null !== $cursor) {
            $query
                ->andWhere('a.collectedAt < :cursorDate OR (a.collectedAt = :cursorDate AND a.id < :cursorId)')
                ->setParameter('cursorDate', new \DateTimeImmutable($cursor['collectedAt']))
                ->setParameter('cursorId', Uuid::fromString($cursor['id']), 'uuid');
        }

        $search = trim($request->query->getString('q'));
        if ('' !== $search) {
            $query->andWhere('LOWER(a.title) LIKE :search OR LOWER(a.summary) LIKE :search OR LOWER(f.title) LIKE :search OR LOWER(subscription.customTitle) LIKE :search')
                ->setParameter('search', '%'.mb_strtolower($search).'%');
        }
        if ('' !== $request->query->getString('subscription')) $query->andWhere('subscription.id = :subscription')->setParameter('subscription', Uuid::fromString($request->query->getString('subscription')), 'uuid');
        if ('' !== $request->query->getString('category')) $query->andWhere('IDENTITY(subscription.category) = :category')->setParameter('category', Uuid::fromString($request->query->getString('category')), 'uuid');
        foreach (['read' => 'isRead', 'favorite' => 'isFavorite', 'saved' => 'isSaved'] as $parameter => $field) { if (in_array($request->query->getString($parameter), ['0','1'], true)) $query->andWhere('COALESCE(s.'.$field.', false) = :'.$parameter)->setParameter($parameter, '1' === $request->query->getString($parameter)); }
        if ('' !== $request->query->getString('from')) $query->andWhere('COALESCE(a.publishedAt, a.collectedAt) >= :from')->setParameter('from', new \DateTimeImmutable($request->query->getString('from')));
        if ('' !== $request->query->getString('to')) $query->andWhere('COALESCE(a.publishedAt, a.collectedAt) <= :to')->setParameter('to', new \DateTimeImmutable($request->query->getString('to')));

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
            'items' => array_map(function (array $item) use ($sanitizer): array {
                $serialized = $this->serializeListItem($item);
                $serialized['imageUrl'] = [] !== $sanitizer->imageUrls($item['content'] ?: $item['summary'] ?: '')
                    ? $this->generateUrl('app_api_article_image', ['id' => $item['id'], 'index' => 0])
                    : null;
                return $serialized;
            }, $items),
            'nextCursor' => $nextCursor,
            'hasMore' => $hasMore,
        ]);
    }

    #[Route('/api/articles/{id}', name: 'app_api_article_show', methods: ['GET'])]
    public function show(string $id, EntityManagerInterface $entityManager, FeedHtmlSanitizer $sanitizer): JsonResponse
    {
        $article = $this->accessibleArticle($id, $entityManager);
        $state = $entityManager->getRepository(ArticleState::class)->findOneBy(['user' => $this->currentUser(), 'article' => $article]);

        return $this->json([
            'id' => (string) $article->getId(),
            'title' => html_entity_decode($article->getTitle(), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'url' => $article->getUrl(),
            'summary' => $article->getSummary(),
            'content' => $article->getContent(),
            'safeHtml' => $sanitizer->sanitize($article->getContent() ?: $article->getSummary() ?: 'Aucun résumé disponible.', fn (int $index): string => $this->generateUrl('app_api_article_image', ['id' => $article->getId(), 'index' => $index])),
            'publishedAt' => $article->getPublishedAt()?->format(DATE_ATOM),
            'source' => $article->getFeed()->getTitle() ?? $article->getFeed()->getCanonicalUrl(),
            'isRead' => $state?->isRead() ?? false,
            'isFavorite' => $state?->isFavorite() ?? false,
            'isSaved' => $state?->isSaved() ?? false,
            'csrfToken' => $this->container->get('security.csrf.token_manager')->getToken('article-state-'.$article->getId())->getValue(),
        ]);
    }

    #[Route('/api/articles/{id}/images/{index}', name: 'app_api_article_image', requirements: ['index' => '\d+'], methods: ['GET'])]
    public function image(string $id, int $index, EntityManagerInterface $entityManager, FeedHtmlSanitizer $sanitizer, FeedImageFetcher $fetcher): Response
    {
        $article = $this->accessibleArticle($id, $entityManager);
        $urls = $sanitizer->imageUrls($article->getContent() ?: $article->getSummary() ?: '');
        if (!isset($urls[$index])) throw $this->createNotFoundException();
        try {
            $image = $fetcher->fetch($urls[$index]);
        } catch (\InvalidArgumentException|\RuntimeException) {
            throw $this->createNotFoundException();
        }
        return new Response($image['body'], Response::HTTP_OK, [
            'Content-Type' => $image['type'],
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cache-Control' => 'private, no-store',
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
        $articles = $entityManager->createQueryBuilder()->select('a')->from(Article::class, 'a')->innerJoin(Subscription::class, 'subscription', 'WITH', 'subscription.feed = a.feed AND subscription.user = :user AND subscription.enabled = true')->where('COALESCE(a.publishedAt, a.collectedAt) < :before')->setParameter('user', $this->currentUser()->getId(), 'uuid')->setParameter('before', $before)->setMaxResults(500)->getQuery()->getResult();
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
            ->setParameter('user', $this->currentUser()->getId(), 'uuid')
            ->setParameter('id', $articleId, 'uuid')
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
        return ['id' => (string) $item['id'], 'title' => html_entity_decode($item['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8'), 'url' => $item['url'], 'summary' => null === $item['summary'] ? null : html_entity_decode(strip_tags($item['summary']), ENT_QUOTES | ENT_HTML5, 'UTF-8'), 'source' => $item['subscriptionTitle'] ?? $item['source'], 'subscriptionId' => (string) $item['subscriptionId'], 'publishedAt' => $item['publishedAt']?->format(DATE_ATOM), 'collectedAt' => $item['collectedAt']->format(DATE_ATOM), 'isRead' => (bool) ($item['isRead'] ?? false), 'isFavorite' => (bool) ($item['isFavorite'] ?? false), 'isSaved' => (bool) ($item['isSaved'] ?? false)];
    }

    private function currentUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) { throw $this->createAccessDeniedException(); }

        return $user;
    }
}
