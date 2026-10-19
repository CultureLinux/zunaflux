<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Domain\Feed\ParsedFeedItem;
use App\Entity\Article;
use App\Entity\ArticleState;
use App\Entity\Feed;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class ArticleStateTest extends TestCase
{
    /** Removing the default unread state or first-read timestamp must make this contract fail. */
    public function testItMarksAnUnreadArticleAsReadOnlyOnce(): void
    {
        $article = new Article(new Feed('https://example.test/feed.xml'), new ParsedFeedItem(
            'article-1',
            'Article de test',
            'https://example.test/article-1',
            null,
            null,
            null,
        ));
        $state = new ArticleState(new User('reader@example.test'), $article);

        self::assertFalse($state->isRead());
        self::assertFalse($state->isFavorite());
        self::assertFalse($state->isSaved());
        self::assertNull($state->getReadAt());

        $state->markRead();
        $firstReadAt = $state->getReadAt();
        $state->markRead();

        self::assertTrue($state->isRead());
        self::assertInstanceOf(\DateTimeImmutable::class, $firstReadAt);
        self::assertSame($firstReadAt, $state->getReadAt());
    }

    /** Regressions must not couple favorite or saved state to the read state. */
    public function testFavoriteAndSavedStatesAreIndependentFromReadingState(): void
    {
        $state = new ArticleState(new User('reader@example.test'), new Article(new Feed('https://example.test/feed.xml'), new ParsedFeedItem(
            'article-2', 'Article de test', null, null, null, null,
        )));

        $state->setFavorite(true);
        $state->setSaved(true);

        self::assertFalse($state->isRead());
        self::assertTrue($state->isFavorite());
        self::assertTrue($state->isSaved());
    }
}
