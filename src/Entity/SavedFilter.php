<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'saved_filter')]
#[ORM\UniqueConstraint(name: 'uniq_saved_filter_user_name', columns: ['user_id', 'name'])]
final class SavedFilter
{
    #[ORM\Id] #[ORM\Column(type: 'uuid')] private Uuid $id;
    #[ORM\ManyToOne] #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')] private User $user;
    #[ORM\Column(length: 100)] private string $name;
    #[ORM\Column(type: 'json')] private array $filters;
    #[ORM\Column(name: 'created_at')] private \DateTimeImmutable $createdAt;
    #[ORM\Column(name: 'updated_at')] private \DateTimeImmutable $updatedAt;
    /** @param array<string,scalar|null> $filters */ public function __construct(User $user, string $name, array $filters) { $this->id=Uuid::v7(); $this->user=$user; $this->rename($name); $this->filters=$filters; $this->createdAt=$this->updatedAt=new \DateTimeImmutable(); }
    public function getId(): Uuid { return $this->id; } public function getUser(): User { return $this->user; } public function getName(): string { return $this->name; } public function getFilters(): array { return $this->filters; }
    public function rename(string $name): void { $name=trim($name); if (''===$name || mb_strlen($name)>100) throw new \InvalidArgumentException('Le nom du filtre doit contenir entre 1 et 100 caractères.'); $this->name=$name; }
}
