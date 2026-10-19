<?php

declare(strict_types=1);

namespace App\Application\Subscription;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ImportOpml
{
    public function __construct(
        private OpmlSubscriptionParser $parser,
        private SubscribeToFeed $subscribeToFeed,
        private CreateCategory $createCategory,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function import(User $user, string $xml): int
    {
        $imported = 0;
        foreach ($this->parser->parse($xml) as $entry) {
            try {
                $feed = new Feed($entry->url);
                $existingFeed = $this->entityManager->getRepository(Feed::class)->findOneBy([
                    'canonicalUrlHash' => $feed->getCanonicalUrlHash(),
                ]);
                if ($existingFeed instanceof Feed && $this->entityManager->getRepository(Subscription::class)->findOneBy([
                    'user' => $user,
                    'feed' => $existingFeed,
                ]) instanceof Subscription) {
                    continue;
                }
                $subscription = $this->subscribeToFeed->subscribe($user, $entry->url);
            } catch (\InvalidArgumentException) {
                continue;
            }

            if (null !== $entry->categoryName) {
                $subscription->assignCategory($this->createCategory->create($user, $entry->categoryName));
            }
            ++$imported;
        }
        $this->entityManager->flush();

        return $imported;
    }
}
