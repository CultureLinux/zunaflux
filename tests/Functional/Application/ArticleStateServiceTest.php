<?php

declare(strict_types=1);

namespace App\Tests\Functional\Application;

use App\Application\Reading\ArticleStateService;
use App\Entity\ArticleState;
use App\Tests\Support\RollbackKernelTestCase;

final class ArticleStateServiceTest extends RollbackKernelTestCase
{
    public function testReadingAnArticleCreatesItsStateAndRecordsTheFirstReadOnce(): void
    {
        $user = $this->user();
        $feed = $this->feed();
        $article = $this->article($feed);
        $this->subscribe($user, $feed);
        $this->em->flush();
        $service = self::getContainer()->get(ArticleStateService::class);

        $state = $service->update($user, $article, ['isRead' => true]);
        self::assertTrue($state->isRead());
        $service->update($user, $article, ['isRead' => false]);
        $service->update($user, $article, ['isRead' => true]);

        $userId = $user->getId()->toBinary();
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM article_state WHERE user_id = ?', [$userId]));
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM first_read_event WHERE user_id = ?', [$userId]));
        self::assertSame(1, $this->scalar('SELECT COALESCE(SUM(first_reads), 0) FROM reading_daily_stat WHERE user_id = ?', [$userId]));
    }

    public function testFavoriteAndSavedAreIndependentFromTheReadFlag(): void
    {
        $user = $this->user();
        $feed = $this->feed();
        $article = $this->article($feed);
        $this->subscribe($user, $feed);
        $this->em->flush();
        $service = self::getContainer()->get(ArticleStateService::class);

        $state = $service->update($user, $article, ['isFavorite' => true, 'isSaved' => true]);

        self::assertTrue($state->isFavorite());
        self::assertTrue($state->isSaved());
        self::assertFalse($state->isRead());
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM first_read_event WHERE user_id = ?', [$user->getId()->toBinary()]));
    }

    public function testAnArticleOfAnUnfollowedOrDisabledSourceIsInaccessible(): void
    {
        $user = $this->user();
        $stranger = $this->user();
        $feed = $this->feed();
        $article = $this->article($feed);
        $disabledFeed = $this->feed('disabled');
        $disabledArticle = $this->article($disabledFeed);
        $this->subscribe($stranger, $feed);
        $this->subscribe($user, $disabledFeed)->disable();
        $this->em->flush();
        $service = self::getContainer()->get(ArticleStateService::class);

        foreach ([$article, $disabledArticle] as $inaccessible) {
            try {
                $service->update($user, $inaccessible, ['isRead' => true]);
                self::fail('The article must be inaccessible.');
            } catch (\DomainException) {
                self::addToAssertionCount(1);
            }
        }
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM article_state WHERE user_id = ?', [$user->getId()->toBinary()]));
    }

    public function testStatesAreScopedPerUser(): void
    {
        $first = $this->user();
        $second = $this->user();
        $feed = $this->feed();
        $article = $this->article($feed);
        $this->subscribe($first, $feed);
        $this->subscribe($second, $feed);
        $this->em->flush();
        $service = self::getContainer()->get(ArticleStateService::class);

        $service->update($first, $article, ['isRead' => true]);

        self::assertNull($this->em->getRepository(ArticleState::class)->findOneBy(['user' => $second, 'article' => $article]));
    }
}
