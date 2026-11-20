<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Feed;
use App\Entity\Subscription;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<Feed> */
class FeedRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Feed::class);
    }

    /**
     * Identifiers of the feeds whose collection is due and that at least one account follows.
     *
     * @return iterable<Uuid>
     */
    public function findDueIds(\DateTimeImmutable $now): iterable
    {
        $query = $this->getEntityManager()->createQueryBuilder()
            ->select('f.id')
            ->from(Feed::class, 'f')
            ->where('f.nextCollectionAt IS NULL OR f.nextCollectionAt <= :now')
            ->andWhere('EXISTS (SELECT 1 FROM '.Subscription::class.' s WHERE s.feed = f AND s.enabled = true)')
            ->setParameter('now', $now, 'datetime_immutable')
            ->getQuery();
        foreach ($query->toIterable() as $row) {
            yield $row['id'] instanceof Uuid ? $row['id'] : Uuid::fromString((string) $row['id']);
        }
    }

    /**
     * Feeds nobody follows any more, newest first.
     *
     * @return list<Feed>
     */
    public function findUnused(): array
    {
        return $this->getEntityManager()
            ->createQuery('SELECT f FROM '.Feed::class.' f WHERE NOT EXISTS (SELECT s.id FROM '.Subscription::class.' s WHERE s.feed = f) ORDER BY f.createdAt DESC')
            ->getResult();
    }

    /**
     * Identifiers of the feeds an administrator synchronises: all, one category's, or a single one.
     *
     * @param 'all'|'category'|'feed' $scope
     *
     * @return list<Uuid>
     */
    public function findIdsForSync(string $scope, ?Uuid $target = null): array
    {
        $connection = $this->getEntityManager()->getConnection();
        $ids = match ($scope) {
            'all' => $connection->fetchFirstColumn('SELECT f.id FROM feed f'),
            'category' => $connection->fetchFirstColumn('SELECT DISTINCT f.id FROM feed f INNER JOIN subscription s ON s.feed_id = f.id WHERE s.category_id = ?', [$target?->toBinary()]),
            'feed' => $connection->fetchFirstColumn('SELECT f.id FROM feed f WHERE f.id = ?', [$target?->toBinary()]),
        };

        return array_map(static fn (string $id): Uuid => Uuid::fromBinary($id), $ids);
    }
}
