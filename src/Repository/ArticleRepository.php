<?php

declare(strict_types=1);

namespace App\Repository;

use App\Application\Reading\ArticleListFilter;
use App\Entity\Article;
use App\Entity\ArticleState;
use App\Entity\Subscription;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Article> */
class ArticleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Article::class);
    }

    /**
     * One keyset page of an account's reading list, newest collected first.
     * Returns up to $limit rows; callers ask for one more than they display to know if a page follows.
     *
     * @return list<array<string, mixed>>
     */
    public function findPage(User $user, ArticleListFilter $filter, int $limit): array
    {
        $query = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('a.id AS id, a.title AS title, a.url AS url, a.summary AS summary, a.content AS content, '
                .'a.publishedAt AS publishedAt, a.collectedAt AS collectedAt, f.title AS source, subscription.id AS '
                .'subscriptionId, subscription.customTitle AS subscriptionTitle, category.id AS categoryId, '
                .'category.name AS categoryName, s.isRead AS isRead, s.isFavorite AS isFavorite, s.isSaved AS isSaved')
            ->from(Article::class, 'a')
            ->innerJoin('a.feed', 'f')
            ->innerJoin(Subscription::class, 'subscription', 'WITH', 'subscription.feed = f AND subscription.user = :user AND subscription.enabled = true')
            ->leftJoin('subscription.category', 'category')
            ->leftJoin(ArticleState::class, 's', 'WITH', 's.article = a AND s.user = :user')
            ->setParameter('user', $user->getId(), 'uuid')
            ->orderBy('a.collectedAt', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->setMaxResults($limit);
        if (null !== $filter->cursor) {
            $query
                ->andWhere('a.collectedAt < :cursorDate OR (a.collectedAt = :cursorDate AND a.id < :cursorId)')
                ->setParameter('cursorDate', $filter->cursor['collectedAt'])
                ->setParameter('cursorId', $filter->cursor['id'], 'uuid');
        }
        if ('' !== $filter->search) {
            $query
                ->andWhere('LOWER(a.title) LIKE :search OR LOWER(a.summary) LIKE :search OR LOWER(f.title) LIKE :search'
                    .' OR LOWER(subscription.customTitle) LIKE :search')
                ->setParameter('search', '%'.mb_strtolower($filter->search).'%');
        }
        if (null !== $filter->subscription) {
            $query->andWhere('subscription.id = :subscription')->setParameter('subscription', $filter->subscription, 'uuid');
        }
        if (null !== $filter->category) {
            $query->andWhere('IDENTITY(subscription.category) = :category')->setParameter('category', $filter->category, 'uuid');
        }
        foreach (['isRead' => $filter->read, 'isFavorite' => $filter->favorite, 'isSaved' => $filter->saved] as $field => $value) {
            if (null !== $value) {
                $query->andWhere('COALESCE(s.'.$field.', false) = :'.$field)->setParameter($field, $value);
            }
        }
        if (null !== $filter->from) {
            $query->andWhere('COALESCE(a.publishedAt, a.collectedAt) >= :from')->setParameter('from', $filter->from);
        }
        if (null !== $filter->to) {
            $query->andWhere('COALESCE(a.publishedAt, a.collectedAt) <= :to')->setParameter('to', $filter->to);
        }

        return $query->getQuery()->getArrayResult();
    }
}
