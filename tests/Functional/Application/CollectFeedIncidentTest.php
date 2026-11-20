<?php

declare(strict_types=1);

namespace App\Tests\Functional\Application;

use App\Application\Feed\CollectFeed;
use App\Application\Feed\FeedSyncRunService;
use App\Application\Notification\NotificationOutbox;
use App\Application\Notification\RelayNotificationOutbox;
use App\Domain\Feed\FeedFetcherInterface;
use App\Domain\Feed\FeedParser;
use App\Domain\Feed\FetchedFeed;
use App\Entity\FeedIncident;
use App\MessageHandler\CollectFeedHandler;
use App\Message\CollectFeedMessage;
use App\Tests\Support\RollbackKernelTestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\MessageBusInterface;

final class CollectFeedIncidentTest extends RollbackKernelTestCase
{
    public function testAnAdminRetryClearsTheIncidentOnSuccessAndTheRunShowsTheOutcome(): void
    {
        $feed = $this->feed();
        $this->subscribe($this->user(), $feed);
        $incident = new FeedIncident($feed, new \RuntimeException('Feed request returned HTTP 503.'));
        $this->em->persist($incident);
        $this->em->flush();
        $bus = $this->recordingBus();
        $runs = new FeedSyncRunService($this->db, $bus);

        $run = $runs->start([$feed->getId()], clearIncident: true);
        $message = $bus->messages[0];
        self::assertTrue($message->clearIncident);
        $this->handler($this->fetcher(succeeds: true))($message);

        $this->em->clear();
        self::assertNull($this->em->getRepository(FeedIncident::class)->findOneBy(['feed' => $feed->getId()]), 'The incident disappears as soon as the retry succeeds.');
        $entries = $runs->entries($run);
        self::assertSame('no_new_articles', $entries[0]['status']);
        self::assertTrue(FeedSyncRunService::isComplete($entries));
    }

    public function testARegularRecoveryKeepsTheIncidentUntilItIsStable(): void
    {
        $feed = $this->feed();
        $this->subscribe($this->user(), $feed);
        $incident = new FeedIncident($feed, new \RuntimeException('Feed request returned HTTP 503.'));
        $this->em->persist($incident);
        $this->em->flush();

        $this->handler($this->fetcher(succeeds: true))(new CollectFeedMessage((string) $feed->getId()));

        $this->em->clear();
        self::assertNotNull($this->em->getRepository(FeedIncident::class)->findOneBy(['feed' => $feed->getId()]));
    }

    public function testAFailedRetryKeepsTheIncidentAndReportsTheErrorInTheRun(): void
    {
        $feed = $this->feed();
        $this->subscribe($this->user(), $feed);
        $incident = new FeedIncident($feed, new \RuntimeException('Feed request returned HTTP 503.'));
        $this->em->persist($incident);
        $this->em->flush();
        $bus = $this->recordingBus();
        $runs = new FeedSyncRunService($this->db, $bus);
        $run = $runs->start([$feed->getId()], clearIncident: true);

        $this->handler($this->fetcher(succeeds: false))($bus->messages[0]);

        $this->em->clear();
        self::assertNotNull($this->em->getRepository(FeedIncident::class)->findOneBy(['feed' => $feed->getId()]));
        $entry = $runs->entries($run)[0];
        self::assertSame('error', $entry['status']);
        self::assertSame('Le serveur du site rencontre une erreur (HTTP 503).', $entry['detail']);
    }

    private function handler(FeedFetcherInterface $fetcher): CollectFeedHandler
    {
        $container = self::getContainer();

        return new CollectFeedHandler(
            $this->em,
            new CollectFeed($this->em, $fetcher, new FeedParser(), $container->get(NotificationOutbox::class)),
            new RelayNotificationOutbox($this->db, new MessageBus(), new NullLogger()),
            new NullLogger(),
        );
    }

    private function fetcher(bool $succeeds): FeedFetcherInterface
    {
        return new class ($succeeds) implements FeedFetcherInterface {
            public function __construct(private readonly bool $succeeds)
            {
            }

            public function fetch(string $url, ?string $etag = null, ?string $lastModified = null): FetchedFeed
            {
                return $this->succeeds ? new FetchedFeed(304, null, null, null, $url) : throw new \RuntimeException('Feed request returned HTTP 503.');
            }
        };
    }

    private function recordingBus(): MessageBusInterface
    {
        return new class () implements MessageBusInterface {
            /** @var list<CollectFeedMessage> */
            public array $messages = [];

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $this->messages[] = $message;

                return new Envelope($message);
            }
        };
    }
}
