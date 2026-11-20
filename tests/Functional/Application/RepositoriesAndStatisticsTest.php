<?php

declare(strict_types=1);

namespace App\Tests\Functional\Application;

use App\Application\Administration\AdminStatistics;
use App\Application\Health\HealthCheck;
use App\Application\Reading\ReadingCounters;
use App\Entity\ArticleState;
use App\Entity\Category;
use App\Repository\FeedRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\UserRepository;
use App\Tests\Support\FrozenClock;
use App\Tests\Support\RollbackKernelTestCase;
use Symfony\Component\Uid\Uuid;

final class RepositoriesAndStatisticsTest extends RollbackKernelTestCase
{
    public function testFeedRepositoryFindsDueUnusedAndSyncTargetFeeds(): void
    {
        $reader = $this->user();
        $due = $this->feed('due');
        $later = $this->feed('later');
        $unused = $this->feed('unused');
        $this->subscribe($reader, $due);
        $inCategory = $this->subscribe($reader, $later);
        $category = new Category($reader, 'Dev');
        $this->em->persist($category);
        $inCategory->assignCategory($category);
        $this->em->flush();
        $this->db->executeStatement('UPDATE feed SET next_collection_at = ? WHERE id = ?', ['2030-01-01 00:00:00', $later->getId()->toBinary()]);
        $repository = self::getContainer()->get(FeedRepository::class);

        $dueNow = array_map('strval', iterator_to_array($repository->findDueIds(new \DateTimeImmutable('2029-01-01')), false));
        self::assertContains((string) $due->getId(), $dueNow);
        self::assertNotContains((string) $later->getId(), $dueNow);
        self::assertNotContains((string) $unused->getId(), $dueNow);
        self::assertContains((string) $later->getId(), array_map('strval', iterator_to_array($repository->findDueIds(new \DateTimeImmutable('2031-01-01')), false)));

        self::assertContains($unused, $repository->findUnused());
        self::assertNotContains($due, $repository->findUnused());
        self::assertSame([(string) $later->getId()], array_map('strval', $repository->findIdsForSync('category', $category->getId())));
        self::assertSame([(string) $due->getId()], array_map('strval', $repository->findIdsForSync('feed', $due->getId())));
        self::assertContains((string) $unused->getId(), array_map('strval', $repository->findIdsForSync('all')));
    }

    public function testSubscriptionRepositoryReportsArticleStatsPerSubscription(): void
    {
        $user = $this->user();
        $feed = $this->feed();
        $empty = $this->feed('empty');
        $subscription = $this->subscribe($user, $feed);
        $emptySubscription = $this->subscribe($user, $empty);
        $this->article($feed, 'one');
        $this->article($feed, 'two');
        $this->em->flush();

        $stats = self::getContainer()->get(SubscriptionRepository::class)->articleStats($user);

        self::assertSame(2, $stats[(string) $subscription->getId()]['articleCount']);
        self::assertArrayNotHasKey((string) $emptySubscription->getId(), $stats);
    }

    public function testReadingCountersSplitReadUnreadFavoriteAndSaved(): void
    {
        $user = $this->user();
        $feed = $this->feed();
        $subscription = $this->subscribe($user, $feed);
        $read = $this->article($feed, 'read');
        $favorite = $this->article($feed, 'fav');
        $this->article($feed, 'plain');
        $readState = new ArticleState($user, $read);
        $readState->markRead();
        $favoriteState = new ArticleState($user, $favorite);
        $favoriteState->setFavorite(true);
        $favoriteState->setSaved(true);
        $this->em->persist($readState);
        $this->em->persist($favoriteState);
        $this->em->flush();
        $counters = self::getContainer()->get(ReadingCounters::class);

        self::assertSame(['unread' => 2, 'read' => 1, 'favorite' => 1, 'saved' => 1], $counters->perSubscription($user)[(string) $subscription->getId()]);
        $summary = $counters->summary($user);
        self::assertSame([3, 1, 2], [(int) $summary['all']['total'], (int) $summary['all']['read_count'], (int) $summary['all']['unread_count']]);
        self::assertSame((string) $feed->getId(), $summary['feeds'][0]['id']);
        self::assertSame([], self::getContainer()->get(ReadingCounters::class)->perSubscription($this->user()));
    }

    public function testAdminStatisticsCountActivityPerDayAndPerAccount(): void
    {
        $user = $this->user();
        $other = $this->user();
        $feed = $this->feed();
        $this->subscribe($user, $feed);
        $this->subscribe($other, $this->feed('other'));
        $this->article($feed, 'a');
        $this->em->flush();
        $statistics = new AdminStatistics($this->db, new FrozenClock(new \DateTimeImmutable('now')));

        $global = $statistics->activity(null);
        $perAccount = $statistics->activity($user);

        self::assertSame(['flux', 'articles', 'comptes', 'notifications envoyées'], array_keys($global));
        self::assertCount(30, $global['flux']);
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $countFor = static fn (array $series): int => array_values(array_filter($series, static fn (array $day): bool => $day['date'] === $today))[0]['count'];
        self::assertGreaterThanOrEqual(2, $countFor($global['comptes']));
        self::assertSame(1, $countFor($perAccount['comptes']));
        self::assertSame(1, $countFor($perAccount['articles']));
        self::assertSame(1, $countFor($perAccount['flux']));
    }

    public function testAdminStatisticsExposeSyncTargetsQuotasAndTheWorkerStatus(): void
    {
        $user = $this->user();
        $this->subscribe($user, $this->feed());
        $this->subscribe($user, $this->feed());
        $category = new Category($user, 'Cible');
        $this->em->persist($category);
        $this->em->flush();
        $this->db->executeStatement("INSERT INTO messenger_worker_heartbeat (name, last_seen_at) VALUES ('async', NOW()) ON DUPLICATE KEY UPDATE last_seen_at = NOW()");
        $statistics = new AdminStatistics($this->db);

        self::assertSame(2, $statistics->subscriptionsPerAccount()[(string) $user->getId()]);
        $targets = $statistics->syncTargets();
        self::assertContains((string) $category->getId(), array_column($targets['categories'], 'id'));
        self::assertNotEmpty($targets['feeds']);
        self::assertTrue(Uuid::isValid($targets['feeds'][0]['id']));
        $worker = $statistics->worker();
        self::assertTrue($worker['workerActive']);
        self::assertIsInt($worker['queueCount']);

        $this->db->executeStatement("UPDATE messenger_worker_heartbeat SET last_seen_at = '2000-01-01 00:00:00' WHERE name = 'async'");
        self::assertFalse($statistics->worker()['workerActive']);
    }

    public function testTheLastActiveAdministratorIsDetectedAndTheHealthCheckCountsQueues(): void
    {
        $this->db->executeStatement("UPDATE app_user SET status = 'disabled' WHERE roles LIKE '%ROLE_ADMIN%'");
        $solo = $this->user('solo');
        $solo->addRole('ROLE_ADMIN');
        $this->em->flush();
        $users = self::getContainer()->get(UserRepository::class);
        self::assertSame(0, $users->countOtherActiveAdministrators($solo));

        $second = $this->user('second');
        $second->addRole('ROLE_ADMIN');
        $this->em->flush();
        self::assertSame(1, $users->countOtherActiveAdministrators($solo));
        $second->disable();
        $this->em->flush();
        self::assertSame(0, $users->countOtherActiveAdministrators($solo));

        $queues = self::getContainer()->get(HealthCheck::class)->queues();
        self::assertIsInt($queues['pending']);
        self::assertIsInt($queues['failed']);
    }
}
