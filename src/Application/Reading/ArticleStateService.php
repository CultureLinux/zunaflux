<?php

declare(strict_types=1);

namespace App\Application\Reading;

use App\Entity\Article;
use App\Entity\ArticleState;
use App\Entity\Subscription;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ArticleStateService
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /** @param array{isRead?: bool, isFavorite?: bool, isSaved?: bool} $changes */
    public function update(User $user, Article $article, array $changes): ArticleState
    {
        $subscription = $this->entityManager->getRepository(Subscription::class)->findOneBy([
            'user' => $user,
            'feed' => $article->getFeed(),
            'enabled' => true,
        ]);

        if (!$subscription instanceof Subscription) {
            throw new \DomainException('Article inaccessible.');
        }

        $state = $this->entityManager->getRepository(ArticleState::class)->findOneBy([
            'user' => $user,
            'article' => $article,
        ]);

        if (!$state instanceof ArticleState) {
            $state = new ArticleState($user, $article);
            $this->entityManager->persist($state);
        }

        if (array_key_exists('isRead', $changes)) {
            $changes['isRead'] ? $state->markRead() : $state->markUnread();
        }
        if (array_key_exists('isFavorite', $changes)) {
            $state->setFavorite($changes['isFavorite']);
        }
        if (array_key_exists('isSaved', $changes)) {
            $state->setSaved($changes['isSaved']);
        }

        $this->entityManager->flush();

        return $state;
    }
}
