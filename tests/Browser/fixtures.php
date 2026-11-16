<?php

declare(strict_types=1);

use App\Domain\Feed\ParsedFeedItem;
use App\Entity\Article;
use App\Entity\ArticleState;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Entity\UserPreference;
use App\Kernel;
use App\Tests\Support\BrowserDatabase;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactory;

ini_set('display_errors', 'stderr');
require dirname(__DIR__, 2).'/vendor/autoload.php';

try {
    $operation = $argv[1] ?? '';
    if (!in_array($operation, ['guard', 'check', 'seed', 'cleanup'], true)) {
        throw new RuntimeException('Expected guard, check, seed or cleanup.');
    }
    $url = getenv('BROWSER_TEST_DATABASE_URL') ?: '';
    $db = BrowserDatabase::connect(getenv('APP_ENV') ?: '', $url);
    if ('guard' === $operation) {
        echo json_encode(['ready' => true], JSON_THROW_ON_ERROR).PHP_EOL;
        exit(0);
    }
    $expected = array_map(static fn (string $file): string => 'DoctrineMigrations\\'.basename($file, '.php'), glob(dirname(__DIR__, 2).'/migrations/Version*.php'));
    sort($expected);
    if ($expected !== $db->fetchFirstColumn('SELECT version FROM doctrine_migration_versions ORDER BY version')) {
        throw new RuntimeException('Apply all repository migrations to the dedicated browser database first.');
    }
    if ('check' === $operation) {
        echo json_encode(['ready' => true], JSON_THROW_ON_ERROR).PHP_EOL;
        exit(0);
    }
    $token = $argv[2] ?? '';
    if (!preg_match('/^[a-f0-9]{32}$/D', $token)) {
        throw new RuntimeException('A random 32-character hexadecimal fixture token is required.');
    }
    $email = 'browser-'.$token.'@example.test';
    $otherEmail = 'browser-other-'.$token.'@example.test';
    $urls = array_map(static fn (string $source): string => 'https://example.test/browser/'.$token.'/'.$source, ['alpha', 'beta', 'private']);
    if ('cleanup' === $operation) {
        $db->transactional(static function () use ($db, $email, $otherEmail, $urls): void {
            $db->executeStatement('DELETE FROM app_user WHERE email_normalized IN (?, ?)', [$email, $otherEmail]);
            foreach ($urls as $feedUrl) {
                $db->delete('feed', ['canonical_url_hash' => hash('sha256', $feedUrl, true)]);
            }
        });
        echo json_encode(['cleaned' => true], JSON_THROW_ON_ERROR).PHP_EOL;
        exit(0);
    }

    // Explicit environment values win over local dotenv defaults in this CLI process.
    foreach (['APP_ENV' => 'browser', 'APP_DEBUG' => '0', 'DATABASE_URL' => $url] as $key => $value) {
        putenv($key.'='.$value);
        $_SERVER[$key] = $_ENV[$key] = $value;
    }
    $kernel = new Kernel('browser', false);
    $kernel->boot();
    $em = $kernel->getContainer()->get('doctrine')->getManager();
    if ($db->fetchOne('SELECT DATABASE()') !== $em->getConnection()->fetchOne('SELECT DATABASE()')) {
        throw new RuntimeException('Kernel database differs from the validated browser target.');
    }
    $password = 'Browser-'.bin2hex(random_bytes(16)).'!';
    $factory = new PasswordHasherFactory([User::class => ['algorithm' => 'auto', 'cost' => 4, 'time_cost' => 3, 'memory_cost' => 10]]);
    $hash = $factory->getPasswordHasher(User::class)->hash($password);
    $user = new User($email);
    $other = new User($otherEmail);
    $user->setPasswordHash($hash);
    $other->setPasswordHash($hash);
    $feeds = [new Feed($urls[0]), new Feed($urls[1]), new Feed($urls[2])];
    foreach (['Source Alpha', 'Source Beta', 'Source privée'] as $index => $title) {
        $feeds[$index]->refreshMetadata($title, null);
    }
    $specifications = [
        'alpha' => [$feeds[0], 'Observabilité des étoiles', 'Contenu détaillé Alpha.', '2026-11-15 12:00:00'],
        'alphaSecond' => [$feeds[0], 'Calendrier des comètes', 'Contenu détaillé des comètes.', '2026-11-15 11:00:00'],
        'beta' => [$feeds[1], 'Jardin des saisons', 'Contenu détaillé Beta.', '2026-11-15 10:00:00'],
        'read' => [$feeds[1], 'Archives du jardin', 'Article déjà lu.', '2026-11-15 09:00:00'],
        'private' => [$feeds[2], 'Secret réservé à un autre compte', 'Contenu privé de contrôle.', '2026-11-15 13:00:00'],
    ];
    $articles = [];
    $em->wrapInTransaction(static function () use ($em, $user, $other, $feeds, $specifications, &$articles): void {
        foreach ([$user, $other, ...$feeds, new Subscription($user, $feeds[0]), new Subscription($user, $feeds[1]), new Subscription($other, $feeds[2])] as $entity) {
            $em->persist($entity);
        }
        foreach ([$user, $other] as $account) {
            $preference = new UserPreference($account);
            $preference->update(['locale' => 'fr', 'readMode' => 'manual', 'sidebarCollapsed' => true]);
            $em->persist($preference);
        }
        foreach ($specifications as $key => [$feed, $title, $body, $date]) {
            $articles[$key] = new Article($feed, new ParsedFeedItem($key, $title, null, '<p>'.$body.'</p>', '<p>'.$body.'</p>', new DateTimeImmutable($date.' UTC')));
            $em->persist($articles[$key]);
        }
        $read = new ArticleState($user, $articles['read']);
        $read->markRead();
        $favorite = new ArticleState($user, $articles['alphaSecond']);
        $favorite->setFavorite(true);
        $em->persist($read);
        $em->persist($favorite);
        $em->flush();
        foreach ($specifications as $key => [, , , $date]) {
            $em->getConnection()->update('article', ['collected_at' => $date], ['id' => $articles[$key]->getId()->toBinary()]);
        }
    });
    $payload = ['email' => $email, 'otherEmail' => $otherEmail, 'password' => $password, 'articles' => []];
    foreach ($articles as $key => $article) {
        $payload['articles'][$key] = ['id' => (string) $article->getId(), 'title' => $article->getTitle()];
    }
    $kernel->shutdown();
    echo json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE).PHP_EOL;
} catch (Throwable $error) {
    // Credentials and driver exceptions must not be emitted by the fixture CLI.
    fwrite(STDERR, 'Browser fixture operation failed ['.get_debug_type($error).']; check the environment, target database and migrations.'.PHP_EOL);
    exit(1);
}
