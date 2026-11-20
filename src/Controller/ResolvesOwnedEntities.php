<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Article;
use App\Entity\Category;
use App\Entity\Subscription;
use App\Entity\User;
use App\Security\Voter\ArticleVoter;
use App\Security\Voter\CategoryVoter;
use App\Security\Voter\SubscriptionVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Loads the entity named by a route identifier and lets the voters decide whether the current
 * account may touch it. Used by controllers extending AbstractController.
 */
trait ResolvesOwnedEntities
{
    private function currentUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    /** Unknown and foreign subscriptions are both refused (403), never revealed. */
    private function ownedSubscription(string $id, EntityManagerInterface $entityManager): Subscription
    {
        $subscription = Uuid::isValid($id) ? $entityManager->getRepository(Subscription::class)->find(Uuid::fromString($id)) : null;
        if (!$subscription instanceof Subscription) {
            throw $this->createAccessDeniedException();
        }
        $this->denyAccessUnlessGranted(SubscriptionVoter::MANAGE, $subscription);

        return $subscription;
    }

    private function ownedCategory(string $id, EntityManagerInterface $entityManager): Category
    {
        $category = Uuid::isValid($id) ? $entityManager->getRepository(Category::class)->find(Uuid::fromString($id)) : null;
        if (!$category instanceof Category) {
            throw $this->createAccessDeniedException();
        }
        $this->denyAccessUnlessGranted(CategoryVoter::MANAGE, $category);

        return $category;
    }

    /** An article the account cannot read does not exist as far as it is concerned (404). */
    private function readableArticle(string $id, EntityManagerInterface $entityManager): Article
    {
        $article = Uuid::isValid($id) ? $entityManager->getRepository(Article::class)->find(Uuid::fromString($id)) : null;
        if (!$article instanceof Article || !$this->isGranted(ArticleVoter::READ, $article)) {
            throw $this->createNotFoundException();
        }

        return $article;
    }
}
