<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<User> */
final class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /** Active administrators other than the given account: zero means it is the last one. */
    public function countOtherActiveAdministrators(User $user): int
    {
        return (int) $this->createQueryBuilder('x')
            ->select('COUNT(x.id)')
            ->where('x.roles LIKE :admin')
            ->andWhere('x.status = :active')
            ->andWhere('x.id != :id')
            ->setParameter('admin', '%"ROLE_ADMIN"%')
            ->setParameter('active', 'active')
            ->setParameter('id', $user->getId(), 'uuid')
            ->getQuery()
            ->getSingleScalarResult();
    }
}
