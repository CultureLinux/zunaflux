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
        $feed = $this->findFeed($candidate);
        $subscription = $feed instanceof Feed
            ? $this->entityManager->getRepository(Subscription::class)->findOneBy(['user' => $user, 'feed' => $feed])
            : null;

        if (!$subscription instanceof Subscription) {
            // Checked before creating anything: a refused subscription leaves no orphan feed.
            $limit = $user->getSubscriptionLimit();
            if (null !== $limit && $this->entityManager->getRepository(Subscription::class)->count(['user' => $user]) >= $limit) {
                throw new \App\Application\Exception\SubscriptionLimitReached();
            }
            $feed ??= $this->createFeed($candidate);
            $subscription = new Subscription($user, $feed);
            $this->entityManager->persist($subscription);
        }

        $this->entityManager->flush();

        return $subscription;
    }

    /**
     * Returns the shared feed for an address, creating it (once, under a lock) when it is new.
     *
     * @throws \App\Application\Exception\InvalidFeedAddress when the address is unusable
     */
    public function resolveFeed(string $url): Feed
    {
        $url = trim($url);
        $parts = parse_url($url);
        if (strlen($url) > 2048 || !filter_var($url, FILTER_VALIDATE_URL) || !is_array($parts) || isset($parts['user']) || isset($parts['pass'])) {
            throw new \App\Application\Exception\InvalidFeedAddress();
        }
        try {
            $candidate = new Feed($url);
        } catch (\InvalidArgumentException $exception) {
            throw new \App\Application\Exception\InvalidFeedAddress($exception);
        }

        return $this->findFeed($candidate) ?? $this->createFeed($candidate);
    }

    private function findFeed(Feed $candidate): ?Feed
    {
        $feed = $this->entityManager->getRepository(Feed::class)->findOneBy([
            'canonicalUrlHash' => $candidate->getCanonicalUrlHash(),
        ]);

        return $feed instanceof Feed ? $feed : null;
    }

    /**
     * Creates the shared feed once even when several users add the same new URL at the
     * same time: the unique index on the canonical URL would otherwise raise a violation
     * and close the entity manager.
     */
    private function createFeed(Feed $candidate): Feed
    {
        $connection = $this->entityManager->getConnection();
        $lockName = 'zunaflux-feed-create-'.substr(bin2hex($candidate->getCanonicalUrlHash()), 0, 32);
        if (1 !== (int) $connection->fetchOne('SELECT GET_LOCK(?, 5)', [$lockName])) {
            throw new \RuntimeException('Unable to serialise the creation of the feed.');
        }

        try {
            $existing = $this->findFeed($candidate);
            if ($existing instanceof Feed) {
                return $existing;
            }
            $this->entityManager->persist($candidate);
            $this->entityManager->flush();

            return $candidate;
        } finally {
            try {
                $connection->fetchOne('SELECT RELEASE_LOCK(?)', [$lockName]);
            } catch (\Throwable) {
                // The lock is bound to the session and disappears with the connection.
            }
        }
    }
}
