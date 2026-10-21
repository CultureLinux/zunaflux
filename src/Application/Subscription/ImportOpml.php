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

    /** @return array{imported: int, duplicate: int, failed: int} */
    public function import(User $user, string $xml): array
    {
        $imported = 0;
        $duplicate = 0;
        $failed = 0;
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
                    ++$duplicate;
                    continue;
                }
                $subscription = $this->subscribeToFeed->subscribe($user, $entry->url);
            } catch (\InvalidArgumentException) {
                ++$failed;
                continue;
            }

            if (null !== $entry->categoryName) {
                $subscription->assignCategory($this->createCategory->create($user, $entry->categoryName));
            }
            ++$imported;
        }
        $this->entityManager->flush();

        return ['imported' => $imported, 'duplicate' => $duplicate, 'failed' => $failed];
    }
}
