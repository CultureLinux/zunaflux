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

        $wasRead = $state->isRead();
        if (array_key_exists('isRead', $changes)) {
            $changes['isRead'] ? $state->markRead() : $state->markUnread();
        }
        if (array_key_exists('isFavorite', $changes)) {
            $state->setFavorite($changes['isFavorite']);
        }
        if (array_key_exists('isSaved', $changes)) {
            $state->setSaved($changes['isSaved']);
        }

        $this->entityManager->wrapInTransaction(function () use ($state, $wasRead, $subscription, $user, $article): void {
            $this->entityManager->flush();

            if (!$wasRead && $state->isRead()) {
                $this->recordFirstRead($user, $article, $subscription);
            }
        });

        return $state;
    }

    private function recordFirstRead(User $user, Article $article, Subscription $subscription): void
    {
        $connection = $this->entityManager->getConnection();
        $now = new \DateTimeImmutable();
        $inserted = $connection->executeStatement(
            'INSERT IGNORE INTO first_read_event (id, user_id, feed_id, article_identity_hash, first_read_at) VALUES (?, ?, ?, ?, ?)',
            [\Symfony\Component\Uid\Uuid::v7()->toBinary(), $user->getId()->toBinary(), $article->getFeed()->getId()->toBinary(), $article->getIdentityHash(), $now],
            [\Doctrine\DBAL\ParameterType::BINARY, \Doctrine\DBAL\ParameterType::BINARY, \Doctrine\DBAL\ParameterType::BINARY, \Doctrine\DBAL\ParameterType::BINARY, \Doctrine\DBAL\Types\Types::DATETIME_IMMUTABLE],
        );
        if (0 === $inserted) {
            return;
        }

        $category = $subscription->getCategory()?->getName() ?? '';
        $connection->executeStatement(
            'INSERT INTO reading_daily_stat (id, user_id, stat_date, category_snapshot, first_reads) VALUES (?, ?, ?, ?, 1) ON DUPLICATE KEY UPDATE first_reads = first_reads + 1',
            [\Symfony\Component\Uid\Uuid::v7()->toBinary(), $user->getId()->toBinary(), $now->format('Y-m-d'), $category],
            [\Doctrine\DBAL\ParameterType::BINARY, \Doctrine\DBAL\ParameterType::BINARY, \Doctrine\DBAL\ParameterType::STRING, \Doctrine\DBAL\ParameterType::STRING],
        );
    }
}
