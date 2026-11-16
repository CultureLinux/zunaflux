<?php

declare(strict_types=1);

namespace App\Application\Reading;

use App\Entity\Article;
use App\Entity\ArticleState;
use App\Entity\Subscription;
use Doctrine\ORM\EntityManagerInterface;

final readonly class MarkSubscriptionRead
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ArticleStateService $stateService,
    ) {
    }

    public function mark(Subscription $subscription): void
    {
        $user = $subscription->getUser();
        $cursor = null;
        do {
            $query = $this->entityManager->createQueryBuilder()
                ->select('a')
                ->from(Article::class, 'a')
                ->leftJoin(ArticleState::class, 's', 'WITH', 's.article = a AND s.user = :user')
                ->where('a.feed = :feed')
                ->andWhere('s.id IS NULL OR s.isRead = false')
                ->setParameter('user', $user->getId(), 'uuid')
                ->setParameter('feed', $subscription->getFeed()->getId(), 'uuid')
                ->orderBy('a.id', 'ASC')
                ->setMaxResults(100);

            if (null !== $cursor) {
                $query->andWhere('a.id > :cursor')->setParameter('cursor', $cursor, 'uuid');
            }

            /** @var list<Article> $articles */
            $articles = $query->getQuery()->getResult();
            foreach ($articles as $article) {
                $this->stateService->update($user, $article, ['isRead' => true]);
                $cursor = $article->getId();
            }
        } while (100 === count($articles));
    }
}
