<?php

declare(strict_types=1);

namespace App\Application\Installation;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final readonly class CreateInitialAdministrator
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    public function isRequired(): bool
    {
        return 0 === (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM app_user');
    }

    public function create(string $email, string $password): bool
    {
        return $this->entityManager->wrapInTransaction(function () use ($email, $password): bool {
            $connection = $this->entityManager->getConnection();
            $connection->executeQuery('SELECT id FROM registration_settings WHERE id = 1 FOR UPDATE');

            if (0 !== (int) $connection->fetchOne('SELECT COUNT(*) FROM app_user')) {
                return false;
            }

            $user = new User($email);
            $user->setPasswordHash($this->passwordHasher->hashPassword($user, $password));
            $user->addRole('ROLE_ADMIN');

            $this->entityManager->persist($user);
            $this->entityManager->flush();

            return true;
        });
    }
}
