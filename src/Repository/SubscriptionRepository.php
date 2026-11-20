<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Article;
use App\Entity\Subscription;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<Subscription> */
class SubscriptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Subscription::class);
    }

    /**
     * Article count and latest publication date of each subscription of an account.
     *
     * @return array<string, array{latestPublication: mixed, articleCount: int}> keyed by subscription id (RFC 4122)
     */
    public function articleStats(User $user): array
    {
        $rows = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('subscription.id AS subscriptionId, MAX(a.publishedAt) AS latestPublication, COUNT(a.id) AS articleCount')
            ->from(Article::class, 'a')
            ->innerJoin(Subscription::class, 'subscription', 'WITH', 'subscription.feed = a.feed AND subscription.user = :user')
            ->setParameter('user', $user->getId(), 'uuid')
            ->groupBy('subscription.id')
            ->getQuery()
            ->getResult();
        $stats = [];
        foreach ($rows as $row) {
            $id = $row['subscriptionId'];
            if (is_string($id) && 16 === strlen($id)) {
                $id = Uuid::fromBinary($id);
            }
            $stats[(string) $id] = ['latestPublication' => $row['latestPublication'], 'articleCount' => (int) $row['articleCount']];
        }

        return $stats;
    }
}
