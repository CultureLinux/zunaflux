<?php

declare(strict_types=1);

namespace App\Application\Subscription;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

final readonly class SubscribeToFeed
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function subscribe(User $user, string $url): Subscription
    {
        $candidate = new Feed($url);
        $feed = $this->entityManager->getRepository(Feed::class)->findOneBy([
            'canonicalUrlHash' => $candidate->getCanonicalUrlHash(),
        ]);

        if (!$feed instanceof Feed) {
            $feed = $candidate;
            $this->entityManager->persist($feed);
        }

        $subscription = $this->entityManager->getRepository(Subscription::class)->findOneBy([
            'user' => $user,
            'feed' => $feed,
        ]);

        if (!$subscription instanceof Subscription) {
            $subscription = new Subscription($user, $feed);
            $this->entityManager->persist($subscription);
        }

        $this->entityManager->flush();

        return $subscription;
    }
}
