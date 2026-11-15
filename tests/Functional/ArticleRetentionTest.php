<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Application\Maintenance\ArticleRetention;
use App\Domain\Feed\ParsedFeedItem;
use App\Entity\Article;
use App\Entity\ArticleState;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Tests\Support\FrozenClock;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ArticleRetentionTest extends KernelTestCase
{
    public function testCalendarCutoffBatchLimitAndProtectionAcrossAccounts(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $db = $em->getConnection();
        $db->beginTransaction();
        try {
            $suffix = bin2hex(random_bytes(8));
            $first = new User('retention-'.$suffix.'@example.test');
            $other = new User('retention-other-'.$suffix.'@example.test');
            $unsubscribed = new User('retention-unsubscribed-'.$suffix.'@example.test');
            $feed = new Feed('https://example.test/retention-'.$suffix);
            $disabledFeed = new Feed('https://example.test/retention-disabled-'.$suffix);
            $disabled = new Subscription($first, $disabledFeed);
            $disabled->disable();
            foreach ([$first, $other, $unsubscribed, $feed, $disabledFeed, new Subscription($first, $feed), new Subscription($other, $feed), $disabled] as $entity) {
                $em->persist($entity);
            }
            $articles = [];
            foreach ([
                'expired' => [$feed, '2025-11-15 11:59:59 UTC'],
                'boundary' => [$feed, '2025-11-15 12:00:00 UTC'],
                'recent' => [$feed, '2025-11-15 12:00:01 UTC'],
                'fallback' => [$feed, null],
                'favorite' => [$feed, '2025-01-01 00:00:00 UTC'],
                'saved_by_other_account' => [$feed, '2025-01-01 00:00:00 UTC'],
                'disabled_favorite' => [$disabledFeed, '2025-01-01 00:00:00 UTC'],
                'unsubscribed_favorite' => [$feed, '2025-01-01 00:00:00 UTC'],
            ] as $name => [$source, $published]) {
                $articles[$name] = new Article($source, new ParsedFeedItem($name, $name, null, null, null, null === $published ? null : new \DateTimeImmutable($published)));
                $em->persist($articles[$name]);
            }
            foreach ([
                [$first, 'favorite', false],
                [$other, 'saved_by_other_account', true],
                [$first, 'disabled_favorite', false],
                [$unsubscribed, 'unsubscribed_favorite', false],
            ] as [$user, $name, $saved]) {
                $state = new ArticleState($user, $articles[$name]);
                if ($saved) {
                    $state->setSaved(true);
                } else {
                    $state->setFavorite(true);
                }
                $em->persist($state);
            }
            $em->flush();
            // Explicit fixture value for articles without a publication date.
            $db->update('article', ['collected_at' => '2025-01-01 00:00:00'], ['id' => $articles['fallback']->getId()->toBinary()]);
            $clock = new FrozenClock(new \DateTimeImmutable('2026-11-15 12:00:00 UTC'));
            $retention = new ArticleRetention($db, 12, $clock);
            // The service is global: bound the loop by the isolated database's size,
            // and roll back every deletion (including pre-existing test rows) below.
            $maximumBatches = (int) $db->fetchOne('SELECT COUNT(*) FROM article') + 1;
            for ($batch = 0; $batch < $maximumBatches; ++$batch) {
                $deleted = $retention->purgeBatch(2);
                self::assertLessThanOrEqual(2, $deleted);
                if (0 === $deleted) {
                    break;
                }
            }
            foreach (['expired', 'fallback', 'disabled_favorite', 'unsubscribed_favorite'] as $name) {
                self::assertFalse($this->exists($db, $articles[$name]), $name);
            }
            foreach (['boundary', 'recent', 'favorite', 'saved_by_other_account'] as $name) {
                self::assertTrue($this->exists($db, $articles[$name]), $name);
            }
            // An exact boundary becomes eligible only once the controlled clock advances.
            $clock->set(new \DateTimeImmutable('2026-11-15 12:00:01 UTC'));
            $retention->purgeBatch(1000);
            self::assertFalse($this->exists($db, $articles['boundary']));
            self::assertTrue($this->exists($db, $articles['recent']));
        } finally {
            if ($db->isTransactionActive()) {
                $db->rollBack();
            }
        }
    }

    private function exists(Connection $db, Article $article): bool
    {
        return 1 === (int) $db->fetchOne('SELECT COUNT(*) FROM article WHERE id = ?', [$article->getId()->toBinary()]);
    }
}
