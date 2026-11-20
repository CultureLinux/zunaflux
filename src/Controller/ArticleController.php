<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Reading\ArticleListFilter;
use App\Application\Reading\ArticleStateService;
use App\Application\Reading\MarkSubscriptionRead;
use App\Application\Reading\ReadingCounters;
use App\Application\Reading\FeedHtmlSanitizer;
use App\Entity\ArticleState;
use App\Entity\Category;
use App\Entity\MastodonAccount;
use App\Entity\Subscription;
use App\Entity\UserPreference;
use App\Infrastructure\Reading\FeedImageFetcher;
use App\Repository\ArticleRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Uid\Uuid;

final class ArticleController extends AbstractController
{
    use ResolvesOwnedEntities;

    public function __construct(private readonly TranslatorInterface $translator)
    {
    }
    private const PAGE_SIZE = 30;

    #[Route('/articles', name: 'app_articles', methods: ['GET'])]
    public function index(EntityManagerInterface $entityManager, ReadingCounters $counters): Response
    {
        $user = $this->currentUser();
        $preference = $entityManager->getRepository(UserPreference::class)->findOneBy(['user' => $user]);
        if (!$preference instanceof UserPreference) {
            $preference = new UserPreference($user);
            $entityManager->persist($preference);
            $entityManager->flush();
        }
        $subscriptions = $entityManager->getRepository(Subscription::class)->findBy(['user' => $user, 'enabled' => true]);
        $readingCounts = $preference->isSidebarCollapsed() ? $counters->perSubscription($user) : [];
        $categories = $entityManager->getRepository(Category::class)->findBy(['user' => $user]);

        return $this->render(
            'article/index.html.twig',
            [
                'preference' => $preference,
                'readingSubscriptions' => $subscriptions,
                'readingCategories' => $categories,
                'readingCounts' => $readingCounts,
                'mastodonConnected' => null !== $entityManager->getRepository(MastodonAccount::class)->findOneBy(['user' => $user]),
            ],
        );
    }

    #[Route('/subscriptions/{id}/mark-read', name: 'app_subscription_mark_read', methods: ['POST'])]
    public function markSubscriptionRead(string $id, Request $request, EntityManagerInterface $entityManager, MarkSubscriptionRead $markRead): Response
    {
        try {
            $uuid = Uuid::fromString($id);
        } catch (\InvalidArgumentException) {
            throw $this->createNotFoundException();
        }
        $user = $this->currentUser();
        $subscription = $entityManager->getRepository(Subscription::class)->findOneBy(['id' => $uuid, 'user' => $user, 'enabled' => true]);
        if (!$subscription instanceof Subscription) {
            throw $this->createNotFoundException();
        }
        if (!$this->isCsrfTokenValid('mark-feed-read-'.$id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $markRead->mark($subscription);

        return $this->redirectToRoute('app_articles', ['subscription' => $id, 'read' => '0']);
    }

    #[Route('/articles/mark-all-read', name: 'app_articles_mark_all_read', methods: ['POST'])]
    public function markAllRead(Request $request, MarkSubscriptionRead $markRead): Response
    {
        if (!$this->isCsrfTokenValid('mark-all-read', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $count = $markRead->markAll($this->currentUser());
        $this->addFlash('success', $this->translator->trans('%count% article(s) marqué(s) comme lu(s).', ['%count%' => $count]));

        return $this->redirectToRoute('app_articles');
    }

    #[Route('/categories/{id}/mark-read', name: 'app_category_mark_read', methods: ['POST'])]
    public function markCategoryRead(string $id, Request $request, EntityManagerInterface $entityManager, MarkSubscriptionRead $markRead): Response
    {
        try {
            $uuid = Uuid::fromString($id);
        } catch (\InvalidArgumentException) {
            throw $this->createNotFoundException();
        }
        $user = $this->currentUser();
        $category = $entityManager->getRepository(Category::class)->findOneBy(['id' => $uuid, 'user' => $user]);
        if (!$category instanceof Category) {
            throw $this->createNotFoundException();
        }
        if (!$this->isCsrfTokenValid('mark-category-read-'.$id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $subscriptions = $entityManager->getRepository(Subscription::class)->findBy(['user' => $user, 'category' => $category, 'enabled' => true]);
        foreach ($subscriptions as $subscription) {
            $markRead->mark($subscription);
        }

        return $this->redirectToRoute('app_articles', ['category' => $id, 'filterName' => $category->getName(), 'read' => '0']);
    }

    #[Route('/api/articles', name: 'app_api_articles', methods: ['GET'])]
    public function list(Request $request, ArticleRepository $articles, FeedHtmlSanitizer $sanitizer): JsonResponse
    {
        $user = $this->currentUser();
        $items = $articles->findPage($user, $this->listFilter($request), self::PAGE_SIZE + 1);
        $hasMore = count($items) > self::PAGE_SIZE;
        if ($hasMore) {
            array_pop($items);
        }
        $nextCursor = null;
        if ($hasMore) {
            $last = $items[array_key_last($items)];
            $nextCursor = $this->encodeCursor($last['collectedAt']->format(DATE_ATOM), (string) $last['id']);
        }

        return $this->json(['items' => array_map(function (array $item) use ($sanitizer): array {
            $serialized = $this->serializeListItem($item);
            $serialized['imageUrl'] = [] !== $sanitizer
                ->imageUrls(($item['content'] ?: $item['summary']) ?: '') ? $this
                ->generateUrl('app_api_article_image', ['id' => $item['id'], 'index' => 0]) : null;

            return $serialized;
        }, $items), 'nextCursor' => $nextCursor, 'hasMore' => $hasMore]);
    }

    #[Route('/api/articles/{id}', name: 'app_api_article_show', methods: ['GET'])]
    public function show(string $id, Request $request, EntityManagerInterface $entityManager, FeedHtmlSanitizer $sanitizer): JsonResponse
    {
        $article = $this->readableArticle($id, $entityManager);
        $state = $entityManager->getRepository(ArticleState::class)->findOneBy(['user' => $this->currentUser(), 'article' => $article]);
        $content = $article->getContent();
        $summary = $article->getSummary();
        $imagePath = fn (int $index, bool $fromSummary = false): string => $this->generateUrl(
            'app_api_article_image',
            ['id' => $article->getId(), 'index' => $index, ...$fromSummary ? ['summary' => 1] : []],
        );
        $renderedImages = [];
        $body = ($content ?: $summary) ?: $this->translator->trans('Aucun résumé disponible.');
        $safeHtml = $sanitizer->sanitize($body, static function (int $index, string $url) use ($imagePath, &$renderedImages): string {
            $renderedImages[$url] = true;

            return $imagePath($index);
        });
        if ($request->query->getBoolean('zen') && $content && $summary) {
            $safeHtml .= $sanitizer->sanitize($summary, static function (int $index, string $url) use ($imagePath, &$renderedImages): ?string {
                if (isset($renderedImages[$url])) {
                    return null;
                }
                $renderedImages[$url] = true;

                return $imagePath($index, true);
            }, imagesOnly: true);
        }

        return $this->json(
            [
                'id' => (string) $article->getId(),
                'title' => html_entity_decode($article->getTitle(), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'url' => $article->getUrl(),
                'summary' => $article->getSummary(),
                'content' => $article->getContent(),
                'safeHtml' => $safeHtml,
                'publishedAt' => $article->getPublishedAt()?->format(DATE_ATOM),
                'source' => $article->getFeed()->getTitle() ?? $article->getFeed()->getCanonicalUrl(),
                'isRead' => $state?->isRead() ?? false,
                'isFavorite' => $state?->isFavorite() ?? false,
                'isSaved' => $state?->isSaved() ?? false,
                'csrfToken' => $this->container->get('security.csrf.token_manager')->getToken('article-state-'.$article->getId())->getValue(),
                'mastodonCsrfToken' => $this->container->get('security.csrf.token_manager')->getToken('mastodon-status-'.$article->getId())->getValue(),
            ],
        );
    }

    #[Route('/api/articles/{id}/images/{index}', name: 'app_api_article_image', requirements: ['index' => '\d+'], methods: ['GET'])]
    public function image(
        string $id,
        int $index,
        Request $request,
        EntityManagerInterface $entityManager,
        FeedHtmlSanitizer $sanitizer,
        FeedImageFetcher $fetcher,
    ): Response {
        $article = $this->readableArticle($id, $entityManager);
        $html = $request->query->getBoolean('summary') ? $article->getSummary() : ($article->getContent() ?: $article->getSummary());
        $urls = $sanitizer->imageUrls($html ?: '');
        if (!isset($urls[$index])) {
            throw $this->createNotFoundException();
        }
        try {
            $image = $fetcher->fetch($urls[$index]);
        } catch (\InvalidArgumentException|\RuntimeException) {
            throw $this->createNotFoundException();
        }

        return new Response(
            $image['body'],
            Response::HTTP_OK,
            [
                'Content-Type' => $image['type'],
                'X-Content-Type-Options' => 'nosniff',
                'Content-Security-Policy' => "default-src 'none'; sandbox",
                'Cache-Control' => 'private, no-store',
            ],
        );
    }

    #[Route('/api/articles/{id}/state', name: 'app_api_article_state', methods: ['PATCH'])]
    public function updateState(string $id, Request $request, EntityManagerInterface $entityManager, ArticleStateService $articleStateService): JsonResponse
    {
        $article = $this->readableArticle($id, $entityManager);
        if (!$this->isCsrfTokenValid('article-state-'.$article->getId(), $request->headers->get('X-CSRF-Token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }
        try {
            $payload = json_decode($request->getContent(), true, 32, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->json(['error' => $this->translator->trans('JSON invalide.')], Response::HTTP_BAD_REQUEST);
        }
        $changes = array_intersect_key($payload, array_flip(['isRead', 'isFavorite', 'isSaved']));
        if ([] === $changes || array_filter($changes, static fn (mixed $value): bool => !is_bool($value)) !== []) {
            return $this->json(['error' => $this->translator->trans('État invalide.')], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $state = $articleStateService->update($this->currentUser(), $article, $changes);

        return $this->json(['isRead' => $state->isRead(), 'isFavorite' => $state->isFavorite(), 'isSaved' => $state->isSaved()]);
    }

    #[Route('/api/articles/bulk', name: 'app_api_articles_bulk', methods: ['POST'])]
    public function bulk(Request $request, EntityManagerInterface $entityManager, ArticleStateService $articleStateService): JsonResponse
    {
        if (!$this->isCsrfTokenValid('article-bulk', $request->headers->get('X-CSRF-Token'))) {
            throw $this->createAccessDeniedException();
        }
        try {
            $payload = json_decode($request->getContent(), true, 32, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->json(['error' => $this->translator->trans('JSON invalide.')], 400);
        }
        $ids = $payload['ids'] ?? null;
        $changes = is_array($payload) ? array_intersect_key($payload, array_flip(['isRead', 'isFavorite', 'isSaved'])) : [];
        if (
            !is_array($ids)
            || [] === $ids
            || count($ids) > 100
            || [] === $changes
            || array_filter($changes, static fn (mixed $value): bool => !is_bool($value)) !== []
        ) {
            return $this->json(['error' => $this->translator->trans('Action invalide.')], 422);
        }
        $changed = 0;
        foreach ($ids as $id) {
            if (!is_string($id)) {
                continue;
            }
            try {
                $articleStateService->update($this->currentUser(), $this->readableArticle($id, $entityManager), $changes);
                ++$changed;
            } catch (\DomainException|\Symfony\Component\HttpKernel\Exception\NotFoundHttpException) {
            }
        }

        return $this->json(['matched' => count($ids), 'changed' => $changed, 'skipped' => count($ids) - $changed]);
    }

    #[Route('/api/articles/mark-before', name: 'app_api_articles_mark_before', methods: ['POST'])]
    public function markBefore(Request $request, MarkSubscriptionRead $markRead): JsonResponse
    {
        if (!$this->isCsrfTokenValid('article-bulk', $request->headers->get('X-CSRF-Token'))) {
            throw $this->createAccessDeniedException();
        }
        try {
            $payload = json_decode($request->getContent(), true, 16, \JSON_THROW_ON_ERROR);
            $before = new \DateTimeImmutable((string) ($payload['before'] ?? ''));
        } catch (\Exception) {
            return $this->json(['error' => $this->translator->trans('Date invalide.')], 422);
        }
        $changed = $markRead->markBefore($this->currentUser(), $before);

        return $this->json(['matched' => $changed, 'changed' => $changed, 'skipped' => 0]);
    }

    private function listFilter(Request $request): ArticleListFilter
    {
        $cursor = $this->decodeCursor($request->query->getString('cursor'));
        $flag = static fn (string $name): ?bool => in_array($request->query->getString($name), ['0', '1'], true) ? '1' === $request->query->getString($name) : null;

        return new ArticleListFilter(
            cursor: null === $cursor ? null : ['collectedAt' => new \DateTimeImmutable($cursor['collectedAt']), 'id' => Uuid::fromString($cursor['id'])],
            search: trim($request->query->getString('q')),
            subscription: '' !== $request->query->getString('subscription') ? $this->queryUuid($request, 'subscription') : null,
            category: '' !== $request->query->getString('category') ? $this->queryUuid($request, 'category') : null,
            read: $flag('read'),
            favorite: $flag('favorite'),
            saved: $flag('saved'),
            from: '' !== $request->query->getString('from') ? $this->queryDate($request, 'from') : null,
            to: '' !== $request->query->getString('to') ? $this->queryDate($request, 'to') : null,
        );
    }

    private function queryUuid(Request $request, string $name): Uuid
    {
        $value = $request->query->getString($name);

        return Uuid::isValid($value) ? Uuid::fromString($value) : throw new BadRequestHttpException(sprintf('Invalid "%s" filter.', $name));
    }

    private function queryDate(Request $request, string $name): \DateTimeImmutable
    {
        try {
            return new \DateTimeImmutable($request->query->getString($name));
        } catch (\Exception) {
            throw new BadRequestHttpException(sprintf('Invalid "%s" filter.', $name));
        }
    }

    /** @return array{collectedAt: string, id: string}|null */
    private function decodeCursor(string $cursor): ?array
    {
        if ('' === $cursor) {
            return null;
        }
        $decoded = base64_decode(strtr($cursor, '-_', '+/').str_repeat('=', (4 - strlen($cursor) % 4) % 4), true);
        if (false === $decoded) {
            throw $this->createNotFoundException();
        }
        try {
            $value = json_decode($decoded, true, 4, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw $this->createNotFoundException();
        }
        if (!is_array($value) || !isset($value['collectedAt'], $value['id']) || !is_string($value['collectedAt']) || !is_string($value['id'])) {
            throw $this->createNotFoundException();
        }
        try {
            new \DateTimeImmutable($value['collectedAt']);
            Uuid::fromString($value['id']);
        } catch (\Exception) {
            throw $this->createNotFoundException();
        }

        return $value;
    }

    private function encodeCursor(string $collectedAt, string $id): string
    {
        return rtrim(strtr(base64_encode(json_encode(['collectedAt' => $collectedAt, 'id' => $id], \JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    /**
     * @param array<string, mixed> $item
     *
     * @return array<string, mixed>
     */
    private function serializeListItem(array $item): array
    {
        return [
            'id' => (string) $item['id'],
            'title' => html_entity_decode($item['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'url' => $item['url'],
            'summary' => null === $item['summary'] ? null : html_entity_decode(strip_tags($item['summary']), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'source' => $item['subscriptionTitle'] ?? $item['source'],
            'subscriptionId' => (string) $item['subscriptionId'],
            'categoryId' => null === $item['categoryId'] ? null : (string) $item['categoryId'],
            'categoryName' => $item['categoryName'],
            'publishedAt' => $item['publishedAt']?->format(DATE_ATOM),
            'collectedAt' => $item['collectedAt']->format(DATE_ATOM),
            'isRead' => (bool) ($item['isRead'] ?? false),
            'isFavorite' => (bool) ($item['isFavorite'] ?? false),
            'isSaved' => (bool) ($item['isSaved'] ?? false),
        ];
    }
}
