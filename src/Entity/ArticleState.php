<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'article_state')]
#[ORM\UniqueConstraint(name: 'uniq_article_state_user_article', columns: ['user_id', 'article_id'])]
final class ArticleState
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'article_id', nullable: false, onDelete: 'CASCADE')]
    private Article $article;

    #[ORM\Column(name: 'is_read')]
    private bool $isRead = false;

    #[ORM\Column(name: 'read_at', nullable: true)]
    private ?\DateTimeImmutable $readAt = null;

    #[ORM\Column(name: 'is_favorite')]
    private bool $isFavorite = false;

    #[ORM\Column(name: 'is_saved')]
    private bool $isSaved = false;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(User $user, Article $article)
    {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->article = $article;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function isRead(): bool { return $this->isRead; }
    public function isFavorite(): bool { return $this->isFavorite; }
    public function isSaved(): bool { return $this->isSaved; }
    public function getReadAt(): ?\DateTimeImmutable { return $this->readAt; }
    public function getArticle(): Article { return $this->article; }
    public function getUser(): User { return $this->user; }

    public function markRead(): void
    {
        if (!$this->isRead) {
            $this->isRead = true;
            $this->readAt = new \DateTimeImmutable();
            $this->touch();
        }
    }

    public function markUnread(): void
    {
        if ($this->isRead) {
            $this->isRead = false;
            $this->readAt = null;
            $this->touch();
        }
    }

    public function setFavorite(bool $favorite): void
    {
        if ($this->isFavorite !== $favorite) {
            $this->isFavorite = $favorite;
            $this->touch();
        }
    }

    public function setSaved(bool $saved): void
    {
        if ($this->isSaved !== $saved) {
            $this->isSaved = $saved;
            $this->touch();
        }
    }

    private function touch(): void { $this->updatedAt = new \DateTimeImmutable(); }
}
