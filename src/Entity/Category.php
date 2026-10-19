<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'category')]
#[ORM\UniqueConstraint(name: 'uniq_category_user_name', columns: ['user_id', 'name'])]
final class Category
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 100)]
    private string $name;

    #[ORM\Column]
    private int $position = 0;

    public function __construct(User $user, string $name, int $position = 0)
    {
        $name = trim($name);
        if ('' === $name) {
            throw new \InvalidArgumentException('A category name cannot be blank.');
        }

        $this->id = Uuid::v7();
        $this->user = $user;
        $this->name = $name;
        $this->position = $position;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getName(): string
    {
        return $this->name;
    }
}
