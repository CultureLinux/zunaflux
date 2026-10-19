<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'subscription')]
#[ORM\UniqueConstraint(name: 'uniq_subscription_user_feed', columns: ['user_id', 'feed_id'])]
final class Subscription
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'feed_id', nullable: false, onDelete: 'CASCADE')]
    private Feed $feed;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'category_id', nullable: true, onDelete: 'SET NULL')]
    private ?Category $category = null;

    #[ORM\Column(name: 'custom_title', length: 255, nullable: true)]
    private ?string $customTitle = null;

    #[ORM\Column]
    private bool $enabled = true;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $user, Feed $feed)
    {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->feed = $feed;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getFeed(): Feed
    {
        return $this->feed;
    }

    public function getCategory(): ?Category
    {
        return $this->category;
    }

    public function assignCategory(?Category $category): void
    {
        if (null !== $category && !$category->getUser()->getId()->equals($this->user->getId())) {
            throw new \DomainException('A subscription category must belong to its user.');
        }

        $this->category = $category;
    }
}
