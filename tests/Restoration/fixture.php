<?php

declare(strict_types=1);

use App\Application\Mastodon\AccessTokenCipher;
use App\Application\Notification\NotificationOutbox;
use App\Domain\Feed\ParsedFeedItem;
use App\Entity\Article;
use App\Entity\ArticleState;
use App\Entity\Feed;
use App\Entity\MastodonAccount;
use App\Entity\PushSubscription;
use App\Entity\Subscription;
use App\Entity\User;
use App\Kernel;
use App\Message\NotifyFeedMessage;

require '/app/vendor/autoload.php';

function check(bool $condition, string $name): void
{
    if (!$condition) {
        throw new RuntimeException('Restore exercise check failed: '.$name);
    }
}

$kernel = null;
try {
    check('1' === getenv('ZUNAFLUX_RESTORE_EXERCISE'), 'explicit exercise guard');
    $url = getenv('DATABASE_URL') ?: '';
    $parts = parse_url($url);
    $database = ltrim($parts['path'] ?? '', '/');
    check(in_array($database, ['zunaflux_restore_source', 'zunaflux_restore_target', 'zunaflux_restore_rollback'], true), 'dedicated database');
    check('database' === ($parts['host'] ?? null), 'isolated Docker database hostname');
    foreach (['APP_ENV' => 'prod', 'APP_DEBUG' => '0', 'DATABASE_URL' => $url] as $key => $value) {
        $_SERVER[$key] = $_ENV[$key] = $value;
    }
    $kernel = new Kernel('prod', false);
    $kernel->boot();
    $em = $kernel->getContainer()->get('doctrine')->getManager();
    $db = $em->getConnection();
    check($database === $db->fetchOne('SELECT DATABASE()'), 'kernel database');
    check((bool) preg_match('/^10\.11\.[0-9]+.*MariaDB/i', (string) $db->fetchOne('SELECT VERSION()')), 'MariaDB 10.11');
    $phase = $argv[1] ?? '';
    $folder = '/exercise-data/'.substr($database, strlen('zunaflux_restore_'));
    $content = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/l9sAAAAASUVORK5CYII=', true);

    if ('seed' === $phase) {
        check('zunaflux_restore_source' === $database, 'seed only on source');
        check(0 === (int) $db->fetchOne('SELECT COUNT(*) FROM app_user'), 'empty source');
        $user = new User('restore-fixture@example.test');
        $user->setPasswordHash(password_hash('Synthetic-restore-password!', PASSWORD_BCRYPT, ['cost' => 4]));
        $feed = new Feed('https://example.test/restore-fixture');
        $feed->refreshMetadata('Restore fixture', null);
        $article = new Article($feed, new ParsedFeedItem('restore-one', 'Restored article', null, 'Synthetic summary', '<p>Synthetic article.</p>', new DateTimeImmutable('2026-11-16 00:00:00 UTC')));
        $state = new ArticleState($user, $article);
        $state->setFavorite(true);
        $state->setSaved(true);
        $cipher = new AccessTokenCipher(getenv('ZUNAFLUX_MASTODON_TOKEN_KEY') ?: '');
        $account = new MastodonAccount($user, 'https://example.test', 'synthetic-id', 'restore@example.test', 'Restore fixture', null, $cipher->encrypt('synthetic-restore-access-token'));
        foreach ([$user, $feed, new Subscription($user, $feed), $article, $state, $account, new PushSubscription($user, 'https://fcm.googleapis.com/fcm/send/restore-synthetic', 'synthetic-public', 'synthetic-auth')] as $entity) {
            $em->persist($entity);
        }
        $em->flush();
        (new NotificationOutbox($db))->add(new NotifyFeedMessage((string) $feed->getId(), 'articles', 1));
        check(mkdir($folder.'/media', 0700, true), 'create media directory');
        check(mkdir($folder.'/config', 0700, true), 'create config directory');
        file_put_contents($folder.'/media/fixture.png', $content);
        $private = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        check(false !== $private, 'VAPID private key');
        openssl_pkey_export($private, $pem);
        $details = openssl_pkey_get_details($private);
        $public = rtrim(strtr(base64_encode("\x04".$details['ec']['x'].$details['ec']['y']), '+/', '-_'), '=');
        file_put_contents($folder.'/config/vapid-private.pem', $pem);
        file_put_contents($folder.'/config/vapid-public.txt', $public);
        file_put_contents($folder.'/config/environment.json', json_encode([
            'APP_SECRET' => getenv('APP_SECRET'),
            'ZUNAFLUX_MASTODON_TOKEN_KEY' => getenv('ZUNAFLUX_MASTODON_TOKEN_KEY'),
            'ZUNAFLUX_PUBLIC_BASE_URL' => getenv('ZUNAFLUX_PUBLIC_BASE_URL'),
            'ZUNAFLUX_VAPID_SUBJECT' => 'mailto:restore@example.test',
            'user_id' => bin2hex($user->getId()->toBinary()),
            'feed_id' => bin2hex($feed->getId()->toBinary()),
            'article_id' => bin2hex($article->getId()->toBinary()),
            'migration_versions' => $db->fetchFirstColumn('SELECT version FROM doctrine_migration_versions ORDER BY version'),
        ], JSON_THROW_ON_ERROR));
        foreach (glob($folder.'/config/*') as $file) {
            chmod($file, 0600);
        }
        (new PharData('/exercise-data/files.tar'))->buildFromDirectory($folder);
    } elseif ('restore-files' === $phase) {
        check('zunaflux_restore_source' !== $database && !file_exists($folder), 'fresh file destination');
        mkdir($folder, 0700, true);
        (new PharData('/exercise-data/files.tar'))->extractTo($folder);
    } elseif ('verify' === $phase) {
        check(1 === (int) $db->fetchOne('SELECT COUNT(*) FROM app_user'), 'restored user');
        check('Restored article' === $db->fetchOne('SELECT title FROM article'), 'restored article');
        $state = $db->fetchAssociative('SELECT is_favorite, is_saved, is_read FROM article_state');
        check(1 === (int) $state['is_favorite'] && 1 === (int) $state['is_saved'] && 0 === (int) $state['is_read'], 'personal state');
        check(1 === (int) $db->fetchOne('SELECT COUNT(*) FROM notification_outbox'), 'durable notification restored');
        check(0 === (int) $db->fetchOne("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'article' AND COLUMN_NAME = 'restore_exercise_marker'"), 'original schema');
        check(hash('sha256', $content) === hash_file('sha256', $folder.'/media/fixture.png'), 'media checksum');
        $config = json_decode(file_get_contents($folder.'/config/environment.json'), true, 512, JSON_THROW_ON_ERROR);
        check($config['user_id'] === bin2hex($db->fetchOne('SELECT id FROM app_user')), 'user identity preserved');
        check($config['feed_id'] === bin2hex($db->fetchOne('SELECT id FROM feed')), 'feed identity preserved');
        check($config['article_id'] === bin2hex($db->fetchOne('SELECT id FROM article')), 'article identity preserved');
        check($config['migration_versions'] === $db->fetchFirstColumn('SELECT version FROM doctrine_migration_versions ORDER BY version'), 'migration versions preserved');
        check(password_verify('Synthetic-restore-password!', $db->fetchOne('SELECT password_hash FROM app_user')), 'restored password readable');
        check($config['APP_SECRET'] === getenv('APP_SECRET'), 'application secret preserved');
        check('https://restore.example.test' === $config['ZUNAFLUX_PUBLIC_BASE_URL'], 'public origin preserved');
        $ciphertext = $db->fetchOne('SELECT access_token_encrypted FROM mastodon_account');
        check('synthetic-restore-access-token' === (new AccessTokenCipher($config['ZUNAFLUX_MASTODON_TOKEN_KEY']))->decrypt($ciphertext), 'restored token decrypts');
        $rejected = false;
        try {
            (new AccessTokenCipher(base64_encode(random_bytes(32))))->decrypt($ciphertext);
        } catch (RuntimeException) {
            $rejected = true;
        }
        check($rejected, 'wrong key rejected');
        $rejected = false;
        try {
            new AccessTokenCipher('');
        } catch (InvalidArgumentException) {
            $rejected = true;
        }
        check($rejected, 'missing key rejected');
        $private = openssl_pkey_get_private(file_get_contents($folder.'/config/vapid-private.pem'));
        check(false !== $private, 'restored VAPID private key');
        $details = openssl_pkey_get_details($private);
        $public = rtrim(strtr(base64_encode("\x04".$details['ec']['x'].$details['ec']['y']), '+/', '-_'), '=');
        check($public === file_get_contents($folder.'/config/vapid-public.txt'), 'VAPID key pair preserved');
    } elseif ('verify-processing' === $phase) {
        check('zunaflux_restore_target' === $database, 'processing target');
        check(1 === (int) $db->fetchOne("SELECT COUNT(*) FROM messenger_messages WHERE queue_name = 'async'"), 'collector queued one feed');
        check(0 === (int) $db->fetchOne("SELECT COUNT(*) FROM messenger_messages WHERE queue_name = 'restore_notifications'"), 'worker acknowledged notification');
        check(0 === (int) $db->fetchOne('SELECT COUNT(*) FROM notification_outbox'), 'outbox relayed');
        check(1 === (int) $db->fetchOne('SELECT SUM(article_count) FROM notification_pending'), 'digest retained');
        check(1 === (int) $db->fetchOne("SELECT COUNT(*) FROM messenger_worker_heartbeat WHERE name = 'async' AND last_seen_at >= NOW() - INTERVAL 2 MINUTE"), 'worker heartbeat');
    } elseif ('mutate' === $phase) {
        check('zunaflux_restore_target' === $database, 'mutate only target');
        $db->executeStatement('ALTER TABLE article ADD COLUMN restore_exercise_marker INT NULL');
        $db->executeStatement("UPDATE article SET title = 'Changed after backup'");
        file_put_contents($folder.'/media/fixture.png', 'Changed after backup');
        check('Changed after backup' === $db->fetchOne('SELECT title FROM article'), 'mutation observed');
    } else {
        throw new RuntimeException('Unknown exercise phase.');
    }
    echo 'Restore exercise '.$phase.": OK\n";
} catch (Throwable $error) {
    // Do not expose connection strings, key material, SQL or tokens in logs.
    $detail = $error instanceof RuntimeException && str_starts_with($error->getMessage(), 'Restore exercise check failed: ') ? ' '.$error->getMessage() : '';
    fwrite(STDERR, 'Restore exercise failed ('.get_debug_type($error).').'.$detail." Inspect the phase and isolated schema locally.\n");
    $failed = true;
} finally {
    $kernel?->shutdown();
}
exit(isset($failed) ? 1 : 0);
