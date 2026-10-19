<?php

declare(strict_types=1);

namespace App\Application\Subscription;

use App\Entity\Category;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

final readonly class CreateCategory
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function create(User $user, string $name): Category
    {
        $category = new Category($user, $name);
        $existing = $this->entityManager->getRepository(Category::class)->findOneBy([
            'user' => $user,
            'name' => $category->getName(),
        ]);

        if ($existing instanceof Category) {
            return $existing;
        }

        $this->entityManager->persist($category);
        $this->entityManager->flush();

        return $category;
    }
}
