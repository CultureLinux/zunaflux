<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Application\Feed\CollectFeed;
use App\Application\Notification\NotificationOutbox;
use App\Application\Notification\RelayNotificationOutbox;
use App\Domain\Feed\FeedFetcherInterface;
use App\Domain\Feed\FeedParser;
use App\Domain\Feed\FetchedFeed;
use App\Entity\Feed;
use App\Entity\PushSubscription;
use App\Entity\Subscription;
use App\Entity\User;
use App\Message\CollectFeedMessage;
use App\MessageHandler\CollectFeedHandler;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Logging\Middleware;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\AbstractLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBus;

final class CollectFeedTraceTest extends KernelTestCase
{
    public function testTracePreservesUsersWithoutDevicesAndEveryDeviceAndExcludesDisabledSubscriptions(): void
    {
        [$entries] = $this->collect(1);
        self::assertCount(3, $entries);
        $byEmail = [];
        foreach ($entries as $entry) {
            self::assertSame('no_new_articles', $entry['status']);
            self::assertSame('articles', $entry['kind']);
            self::assertSame(0, $entry['article_count']);
            $byEmail[$entry['user_email']][] = $entry;
        }
        self::assertCount(2, $byEmail);
        $withoutDevice = array_values(array_filter($entries, static fn (array $entry): bool => null === $entry['device_id']));
        self::assertCount(1, $withoutDevice);
        self::assertNull($withoutDevice[0]['device_label']);
        $labels = array_column(array_filter($entries, static fn (array $entry): bool => null !== $entry['device_id']), 'device_label');
        sort($labels);
        self::assertSame(['Desktop', 'Mobile'], $labels);
    }

    public function testTraceSelectCountRemainsBoundedWithManySubscribers(): void
    {
        [$entries, $selects] = $this->collect(25);
        self::assertCount(27, $entries);
        // Feed lookup, incident lookup and the trace projection must suffice (the per-feed
        // advisory lock SELECTs are a constant cost and are not counted),
        // regardless of subscriber count; leave one query of headroom.
        self::assertLessThanOrEqual(4, $selects);
    }

    private function collect(int $withoutDevices): array
    {
        self::bootKernel();
        $original = self::getContainer()->get(EntityManagerInterface::class);
        $queryLogger = new class extends AbstractLogger {
            public int $selects = 0;
            public function log($level, \Stringable|string $message, array $context = []): void
            {
                if (isset($context['sql']) && preg_match('/^\s*SELECT\b(?!\s+(?:GET|RELEASE)_LOCK)/i', $context['sql'])) {
                    ++$this->selects;
                }
            }
        };
        $configuration = new Configuration();
        $configuration->setMiddlewares([new Middleware($queryLogger)]);
        $db = DriverManager::getConnection($original->getConnection()->getParams(), $configuration);
        $em = new EntityManager($db, $original->getConfiguration());
        $db->beginTransaction();
        try {
            $suffix = bin2hex(random_bytes(8));
            $feed = new Feed('https://example.test/trace-'.$suffix);
            $otherFeed = new Feed('https://example.test/other-'.$suffix);
            $em->persist($feed);
            $em->persist($otherFeed);
            for ($index = 0; $index < $withoutDevices; ++$index) {
                $user = new User('trace-'.$suffix.'-'.$index.'@example.test');
                $em->persist($user);
                $em->persist(new Subscription($user, $feed));
            }
            foreach (['active', 'disabled', 'other_feed'] as $kind) {
                $user = new User('trace-'.$suffix.'-'.$kind.'@example.test');
                $subscription = new Subscription($user, 'other_feed' === $kind ? $otherFeed : $feed);
                if ('disabled' === $kind) {
                    $subscription->disable();
                }
                $em->persist($user);
                $em->persist($subscription);
                foreach (['Desktop', 'Mobile'] as $label) {
                    $device = new PushSubscription($user, 'https://example.test/push/'.$suffix.'/'.$kind.'/'.$label, 'synthetic-key', 'synthetic-auth');
                    $device->setDeviceLabel($label);
                    $em->persist($device);
                }
            }
            $em->flush();
            $feedId = $feed->getId();
            $em->clear();
            $logger = new class extends AbstractLogger {
                public array $entries = [];
                public function log($level, \Stringable|string $message, array $context = []): void
                {
                    $this->entries[] = $context;
                }
            };
            $fetcher = new class implements FeedFetcherInterface {
                public function fetch(string $url, ?string $etag = null, ?string $lastModified = null): FetchedFeed
                {
                    return new FetchedFeed(304, null, null, null, $url);
                }
            };
            $handler = new CollectFeedHandler($em, new CollectFeed($em, $fetcher, new FeedParser(), new NotificationOutbox($db)), new RelayNotificationOutbox($db, new MessageBus(), $logger), $logger);
            $queryLogger->selects = 0;
            $handler(new CollectFeedMessage((string) $feedId));

            return [$logger->entries, $queryLogger->selects];
        } finally {
            while ($db->isTransactionActive()) {
                $db->rollBack();
            }
            $em->close();
            $db->close();
        }
    }
}
