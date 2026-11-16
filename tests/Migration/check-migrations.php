<?php

declare(strict_types=1);

use App\Message\NotifyFeedMessage;
use App\Tests\Support\MigrationDatabase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\BusNameStamp;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Uid\Uuid;

require dirname(__DIR__, 2).'/vendor/autoload.php';

/** Execute the actual Symfony migration command with an explicit test-only URL. */
function migrate(string $target, string $url): void
{
    $root = dirname(__DIR__, 2);
    $environment = array_merge(getenv(), ['APP_ENV' => 'test', 'APP_DEBUG' => '0', 'DATABASE_URL' => $url]);
    $process = proc_open(
        [PHP_BINARY, $root.'/bin/console', 'doctrine:migrations:migrate', $target, '--env=test', '--no-interaction'],
        [STDIN, STDOUT, STDERR],
        $pipes,
        $root,
        $environment,
    );
    if (false === $process || 0 !== proc_close($process)) {
        throw new RuntimeException('Migration command failed.');
    }
}

/** @return array<string, list<array<string, mixed>>> */
function snapshot(Connection $db): array
{
    $rows = [];
    foreach (['app_user', 'feed', 'subscription', 'article', 'notification_pending', 'messenger_messages'] as $table) {
        // All identifiers are fixed literals, never command-line input.
        $rows[$table] = $db->fetchAllAssociative('SELECT * FROM '.$table.' ORDER BY id');
    }

    return $rows;
}

function seedPreviousSchema(Connection $db): void
{
    $user = Uuid::v7()->toBinary();
    $feed = Uuid::v7()->toBinary();
    $subscription = Uuid::v7()->toBinary();
    $now = '2026-11-14 12:00:00';
    $url = 'https://example.test/migration-fixture';
    $db->insert('app_user', ['id' => $user, 'email_normalized' => 'migration@example.test', 'password_hash' => 'test-only', 'roles' => '["ROLE_USER"]', 'status' => 'active', 'created_at' => $now]);
    $db->insert('feed', ['id' => $feed, 'canonical_url' => $url, 'canonical_url_hash' => hash('sha256', $url, true), 'title' => 'Migration fixture', 'status' => 'active', 'created_at' => $now]);
    $db->insert('subscription', ['id' => $subscription, 'user_id' => $user, 'feed_id' => $feed, 'enabled' => 1, 'created_at' => $now]);
    $db->insert('article', ['id' => Uuid::v7()->toBinary(), 'feed_id' => $feed, 'identity' => 'fixture', 'identity_hash' => hash('sha256', 'fixture', true), 'title' => 'Article conservé', 'collected_at' => $now]);
    $db->insert('notification_pending', ['subscription_id' => $subscription, 'event_id' => Uuid::v7()->toBinary(), 'article_count' => 3, 'created_at' => $now]);
    // A real serialized notification envelope, kept for preservation checks only.
    $encoded = (new PhpSerializer())->encode(new Envelope(
        new NotifyFeedMessage((string) Uuid::fromBinary($feed), 'articles', 3),
        [new BusNameStamp('messenger.bus.default')],
    ));
    $db->insert('messenger_messages', ['body' => $encoded['body'], 'headers' => json_encode($encoded['headers'] ?? [], JSON_THROW_ON_ERROR), 'queue_name' => 'async', 'created_at' => $now, 'available_at' => $now]);
}

try {
    $scenario = $argv[1] ?? '';
    if (!in_array($scenario, ['empty', 'upgrade'], true)) {
        throw new RuntimeException('Usage: php85 tests/Migration/check-migrations.php empty|upgrade');
    }
    $url = getenv('MIGRATION_TEST_DATABASE_URL') ?: '';
    $parameters = MigrationDatabase::parameters(getenv('APP_ENV') ?: '', $url);
    $db = DriverManager::getConnection($parameters);
    // No schema/data write is allowed before every preflight condition passed.
    $server = (string) $db->fetchOne('SELECT VERSION()');
    if (!preg_match('/^10\.11\.[0-9]+.*MariaDB/i', $server)) {
        throw new RuntimeException('Migration checks require MariaDB 10.11.');
    }
    if ($parameters['dbname'] !== $db->fetchOne('SELECT DATABASE()')) {
        throw new RuntimeException('Connected database differs from the validated test target.');
    }
    $objects = (int) $db->fetchOne('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()');
    if (0 !== $objects) {
        throw new RuntimeException('Migration test database must be empty, including metadata tables and views.');
    }

    $before = null;
    if ('upgrade' === $scenario) {
        migrate('DoctrineMigrations\\Version20261114120000', $url);
        if (0 !== (int) $db->fetchOne("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notification_outbox'")) {
            throw new RuntimeException('The previous schema unexpectedly contains the new outbox.');
        }
        seedPreviousSchema($db);
        $before = snapshot($db);
    }
    migrate('latest', $url);

    $files = glob(dirname(__DIR__, 2).'/migrations/Version*.php');
    $expected = array_map(static fn (string $file): string => 'DoctrineMigrations\\'.basename($file, '.php'), $files);
    sort($expected);
    $actual = $db->fetchFirstColumn('SELECT version FROM doctrine_migration_versions ORDER BY version');
    if ($expected !== $actual) {
        throw new RuntimeException('Executed migrations differ from repository migration files.');
    }
    $tables = $db->fetchFirstColumn('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()');
    if (array_diff(['notification_pending', 'notification_outbox', 'messenger_messages'], $tables)) {
        throw new RuntimeException('Expected durable notification tables are missing.');
    }
    $columns = $db->fetchFirstColumn("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'push_subscription'");
    if (array_diff(['digest_cursor', 'digest_sent_timestamp'], $columns)) {
        throw new RuntimeException('Digest cursor columns are missing.');
    }
    $unique = $db->fetchFirstColumn("SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notification_pending' AND INDEX_NAME = 'uniq_notification_event' AND NON_UNIQUE = 0 ORDER BY SEQ_IN_INDEX");
    if (['subscription_id', 'event_id'] !== $unique) {
        throw new RuntimeException('Notification deduplication constraint is missing.');
    }
    if (null !== $before && $before !== snapshot($db)) {
        throw new RuntimeException('Upgrade changed existing fixture data.');
    }
    // Second application must be a no-op, including preservation of existing rows.
    $after = snapshot($db);
    migrate('latest', $url);
    if ($after !== snapshot($db)) {
        throw new RuntimeException('Repeated migration application changed existing data.');
    }
    $db->close();
    fwrite(STDOUT, 'Migration check '.$scenario.': passed (MariaDB 10.11, isolated schema, data preserved).'.PHP_EOL);
} catch (Throwable $error) {
    // Do not print connection URLs or credentials from driver exceptions.
    $safe = $error instanceof RuntimeException && !($error instanceof \Doctrine\DBAL\Exception);
    fwrite(STDERR, 'Migration check failed: '.($safe ? $error->getMessage() : 'database/configuration error; inspect the isolated test environment.').PHP_EOL);
    exit(1);
}
