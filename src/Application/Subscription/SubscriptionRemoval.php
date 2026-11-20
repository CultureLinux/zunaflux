<?php

declare(strict_types=1);

namespace App\Application\Subscription;

use App\Entity\Category;
use App\Entity\Subscription;
use App\Entity\User;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;

/** Removes subscriptions together with the reading states the account kept on their articles. */
final readonly class SubscriptionRemoval
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function remove(Subscription $subscription): void
    {
        $this->entityManager->wrapInTransaction(function () use ($subscription): void {
            $this->deleteStates($subscription);
            $this->entityManager->remove($subscription);
            $this->entityManager->flush();
        });
    }

    /** Removes a category and every subscription filed under it. */
    public function removeCategory(User $user, Category $category): void
    {
        $this->entityManager->wrapInTransaction(function () use ($user, $category): void {
            foreach ($this->entityManager->getRepository(Subscription::class)->findBy(['user' => $user, 'category' => $category]) as $subscription) {
                $this->deleteStates($subscription);
                $this->entityManager->remove($subscription);
            }
            $this->entityManager->remove($category);
            $this->entityManager->flush();
        });
    }

    private function deleteStates(Subscription $subscription): void
    {
        $this->entityManager->getConnection()->executeStatement(
            'DELETE article_state FROM article_state INNER JOIN article ON article.id = article_state.article_id'
            .' WHERE article_state.user_id = ? AND article.feed_id = ?',
            [$subscription->getUser()->getId()->toBinary(), $subscription->getFeed()->getId()->toBinary()],
            [ParameterType::BINARY, ParameterType::BINARY],
        );
    }
}
