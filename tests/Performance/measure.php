<?php

declare(strict_types=1);

use App\Application\Feed\CollectFeed;
use App\Application\Maintenance\ArticleRetention;
use App\Application\Notification\NotificationOutbox;
use App\Application\Notification\RelayNotificationOutbox;
use App\Application\Reading\FeedHtmlSanitizer;
use App\Controller\ArticleController;
use App\Domain\Feed\FeedFetcherInterface;
use App\Domain\Feed\FeedParser;
use App\Domain\Feed\FetchedFeed;
use App\Domain\Feed\ParsedFeedItem;
use App\Entity\Article;
use App\Entity\ArticleState;
use App\Entity\Feed;
use App\Entity\PushSubscription;
use App\Entity\Subscription;
use App\Entity\User;
use App\Kernel;
use App\Message\CollectFeedMessage;
use App\MessageHandler\CollectFeedHandler;
use App\Tests\Support\FrozenClock;
use App\Tests\Support\PerformanceDatabase;
use App\Tests\Support\PerformancePlan;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Logging\Middleware;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\AbstractLogger;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

require dirname(__DIR__).'/bootstrap.php';

// Deliberately retain SQL and bindings only in process memory, never in reports.
$queries = new class extends AbstractLogger {
    public array $entries = [];
    public function log($level, Stringable|string $message, array $context = []): void
    {
        if (isset($context['sql'])) {
            $this->entries[] = $context;
        }
    }
};
$kernel = null;
$db = null;
$phase = 'database_guard';
try {
    $parameters = PerformanceDatabase::parameters(
        $_SERVER['APP_ENV'] ?? getenv('APP_ENV') ?: '',
        getenv('PERFORMANCE_TEST_DATABASE_URL') ?: '',
    );
    $configuration = new Configuration();
    $configuration->setMiddlewares([new Middleware($queries)]);
    $db = DriverManager::getConnection($parameters, $configuration);
    PerformanceDatabase::guard($db);
    if ('guard' === ($argv[1] ?? '')) {
        fwrite(STDOUT, "Dedicated MariaDB 10.11 performance target accepted.\n");
        exit(0);
    }
    if ('run' !== ($argv[1] ?? '')) {
        throw new RuntimeException('Expected guard or run.');
    }
    // Never boot a kernel with the developer's normal DATABASE_URL.
    $url = getenv('PERFORMANCE_TEST_DATABASE_URL');
    putenv('DATABASE_URL='.$url);
    $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = $url;
    $phase = 'kernel_and_schema';
    $kernel = new Kernel('test', false);
    $kernel->boot();
    $original = $kernel->getContainer()->get('test.service_container')->get(EntityManagerInterface::class);
    $em = new EntityManager($db, $original->getConfiguration());
    foreach (['app_user', 'feed', 'article', 'subscription', 'push_subscription', 'article_state'] as $table) {
        if ('InnoDB' !== $db->fetchOne('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table])) {
            throw new RuntimeException('Performance fixtures require transactional InnoDB tables.');
        }
        if (0 !== (int) $db->fetchOne('SELECT COUNT(*) FROM '.$table)) {
            throw new RuntimeException('Performance database must contain no application fixtures.');
        }
    }
    $db->beginTransaction();
    $logger = new class extends AbstractLogger {
        public int $notifications = 0;
        public bool $error = false;
        public function log($level, Stringable|string $message, array $context = []): void
        {
            if ('no_new_articles' === ($context['status'] ?? null)) {
                ++$this->notifications;
            }
            if ('error' === $level) {
                $this->error = true;
            }
        }
    };
    $fetcher = new class implements FeedFetcherInterface {
        public function fetch(string $url, ?string $etag = null, ?string $lastModified = null): FetchedFeed
        {
            return new FetchedFeed(304, null, null, null, $url);
        }
    };
    $collect = new CollectFeed($em, $fetcher, new FeedParser(), new NotificationOutbox($db));
    $handler = new CollectFeedHandler($em, $collect, new RelayNotificationOutbox($db, new MessageBus(), $logger), $logger);
    $report = [
        'format' => 1,
        'revision' => 'Record git rev-parse HEAD and working-tree state alongside this report.',
        'php' => PHP_VERSION,
        'mariadb' => $db->fetchOne('SELECT VERSION()'),
        'protocol' => ['warmups' => 1, 'samples' => 5, 'orm_clear_before_sample' => true, 'network' => false, 'http' => false, 'plan_format' => 'tabular'],
        'cases' => [],
    ];
    $measure = static function (string $name, callable $operation) use ($db, $em, $queries, &$report, &$phase): void {
        $samples = [];
        $plans = [];
        for ($sample = 0; $sample < 6; ++$sample) {
            $phase = $name.':sample_'.$sample;
            $em->clear();
            $db->beginTransaction();
            try {
                $queries->entries = [];
                $start = hrtime(true);
                $operation();
                $milliseconds = (hrtime(true) - $start) / 1_000_000;
                $captured = $queries->entries;
                if ($sample > 0) {
                    $samples[] = ['milliseconds' => round($milliseconds, 3), 'sql_statements' => count($captured)];
                }
                if (1 === $sample) {
                    foreach ($captured as $entry) {
                        if (!preg_match('/^\s*SELECT\b/i', $entry['sql'])) {
                            continue;
                        }
                        $fingerprint = hash('sha256', $entry['sql']);
                        if (!isset($plans[$fingerprint])) {
                            $phase = $name.':explain';
                            $rows = $db->fetchAllAssociative('EXPLAIN '.$entry['sql'], $entry['params'] ?? [], $entry['types'] ?? []);
                            $plans[$fingerprint] = PerformancePlan::summarize($rows);
                        }
                    }
                }
            } finally {
                $db->rollBack();
            }
        }
        $times = array_column($samples, 'milliseconds');
        sort($times);
        $report['cases'][$name] = ['samples' => $samples, 'median_ms' => $times[2], 'max_ms' => $times[4], 'select_plans' => $plans];
    };
    $feed = new Feed('https://example.test/performance-collection');
    $feedId = $feed->getId();
    $em->persist($feed);
    $em->flush();
    $userIds = [];
    $previous = 0;
    foreach ([1, 10, 100] as $subscribers) {
        $feed = $em->find(Feed::class, $feedId);
        for ($index = $previous; $index < $subscribers; ++$index) {
            $user = new User('performance-'.$index.'@example.test');
            $userIds[] = $user->getId();
            foreach ([$user, new Subscription($user, $feed), new PushSubscription($user, 'https://example.test/push/'.$index, 'synthetic-key', 'synthetic-auth')] as $entity) {
                $em->persist($entity);
            }
        }
        $em->flush();
        $measure('collect_304_'.$subscribers.'_subscribers', static function () use ($handler, $feedId, $logger, $subscribers): void {
            $logger->notifications = 0;
            $logger->error = false;
            $handler(new CollectFeedMessage((string) $feedId));
            if ($logger->error || $subscribers !== $logger->notifications) {
                throw new RuntimeException('Incomplete collection notification trace.');
            }
        });
        $previous = $subscribers;
    }
    // 10 sources, 10,000 articles, 20% read, 10% protected by favorites.
    for ($source = 0; $source < 10; ++$source) {
        $phase = 'reading_fixtures';
        $user = $em->find(User::class, $userIds[0]);
        $feed = new Feed('https://example.test/performance-reading/'.$source);
        $em->persist($feed);
        $em->persist(new Subscription($user, $feed));
        for ($index = 0; $index < 1000; ++$index) {
            $article = new Article($feed, new ParsedFeedItem((string) $index, 'Performance article '.$index, null, '<p>Contenu synthétique benchmark</p>', null, new DateTimeImmutable('2020-01-01 UTC')));
            $em->persist($article);
            if (0 === $index % 5) {
                $state = new ArticleState($user, $article);
                $state->markRead();
                $state->setFavorite(0 === $index % 10);
                $em->persist($state);
            }
        }
        $em->flush();
        $db->executeStatement('UPDATE article SET collected_at = ? WHERE feed_id = ?', ['2020-01-01 00:00:00', $feed->getId()->toBinary()]);
        $em->clear();
    }
    $controller = new ArticleController();
    $services = new Container();
    $tokens = new TokenStorage();
    $services->set('security.token_storage', $tokens);
    $controller->setContainer($services);
    foreach (['read_first_page' => [], 'read_unread' => ['read' => '0'], 'search_match' => ['q' => 'benchmark'], 'search_missing' => ['q' => 'absent-term']] as $name => $filters) {
        $measure($name, static function () use ($em, $userIds, $tokens, $controller, $filters): void {
            $user = $em->find(User::class, $userIds[0]);
            $tokens->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
            $response = $controller->list(Request::create('/api/articles', 'GET', $filters), $em, new FeedHtmlSanitizer());
            $payload = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
            $expected = isset($filters['q']) && 'absent-term' === $filters['q'] ? 0 : 30;
            if (200 !== $response->getStatusCode() || $expected !== count($payload['items'])) {
                throw new RuntimeException('Unexpected reader result.');
            }
        });
    }
    $retention = new ArticleRetention($db, 12, new FrozenClock(new DateTimeImmutable('2026-11-16 12:00:00 UTC')));
    $measure('purge_100', static function () use ($retention): void {
        if (100 !== $retention->purgeBatch(100)) {
            throw new RuntimeException('Unexpected purge result.');
        }
    });
    $report['dataset'] = ['users' => 100, 'feeds' => 11, 'subscriptions' => 110, 'devices' => 100, 'articles' => 10000, 'states' => 2000, 'favorites' => 1000];
    $db->rollBack();
    foreach (['app_user', 'feed', 'article', 'subscription', 'push_subscription', 'article_state'] as $table) {
        if (0 !== (int) $db->fetchOne('SELECT COUNT(*) FROM '.$table)) {
            throw new RuntimeException('Performance fixtures were not rolled back.');
        }
    }
    $report['rollback_verified'] = true;
    $phase = 'report_encoding';
    fwrite(STDOUT, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
} catch (Throwable $error) {
    // Connection errors may contain credentials; diagnostics intentionally omit them.
    $jsonDetail = $error instanceof JsonException ? '; JSON code '.$error->getCode() : '';
    fwrite(STDERR, 'Performance measurement failed ('.get_debug_type($error).'; phase '.$phase.$jsonDetail."). Check the dedicated DSN, empty migrated schema and source compatibility.\n");
    $failed = true;
} finally {
    if (null !== $db) {
        while ($db->isTransactionActive()) {
            $db->rollBack();
        }
        $db->close();
    }
    $kernel?->shutdown();
}
exit(isset($failed) ? 1 : 0);
