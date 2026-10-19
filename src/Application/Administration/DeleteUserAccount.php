<?php

declare(strict_types=1);

namespace App\Application\Administration;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DeleteUserAccount
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function delete(User $user): void
    {
        if ($user->isActive()) {
            throw new \DomainException('Le compte doit être désactivé avant sa suppression définitive.');
        }

        $this->entityManager->wrapInTransaction(function () use ($user): void {
            $this->entityManager->remove($user);
            $this->entityManager->flush();
        });
    }
}
