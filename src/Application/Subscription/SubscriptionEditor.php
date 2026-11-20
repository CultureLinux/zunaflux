<?php

declare(strict_types=1);

namespace App\Application\Subscription;

use App\Application\Exception\AlreadySubscribed;
use App\Entity\Category;
use App\Entity\Subscription;
use Doctrine\ORM\EntityManagerInterface;

/** Edits a subscription (address, category, title, enabled flag) in one transaction. */
final readonly class SubscriptionEditor
{
    public function __construct(private EntityManagerInterface $entityManager, private SubscribeToFeed $subscribeToFeed)
    {
    }

    /**
     * @throws \InvalidArgumentException when the address is unusable or already followed by the account
     */
    public function update(Subscription $subscription, string $feedUrl, ?Category $category, ?string $customTitle, bool $enabled): void
    {
        $this->entityManager->wrapInTransaction(function () use ($subscription, $feedUrl, $category, $customTitle, $enabled): void {
            $feed = $this->subscribeToFeed->resolveFeed($feedUrl);
            $existing = $this->entityManager->getRepository(Subscription::class)->findOneBy(['user' => $subscription->getUser(), 'feed' => $feed]);
            if ($existing instanceof Subscription && !$existing->getId()->equals($subscription->getId())) {
                throw new AlreadySubscribed();
            }
            $subscription->assignCategory($category);
            $subscription->rename($customTitle);
            // A moved or disabled subscription must not deliver notifications queued for its old state.
            if (!$subscription->getFeed()->getId()->equals($feed->getId()) || !$enabled) {
                $this->entityManager->getConnection()->executeStatement('DELETE FROM notification_pending WHERE subscription_id = ?', [$subscription->getId()->toBinary()]);
            }
            $subscription->changeFeed($feed);
            $enabled ? $subscription->enable() : $subscription->disable();
            $this->entityManager->flush();
        });
    }
}
