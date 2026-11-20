<?php

declare(strict_types=1);

namespace App\Tests\Functional\Application;

use App\Application\Reading\ArticleListFilter;
use App\Entity\ArticleState;
use App\Entity\Category;
use App\Repository\ArticleRepository;
use App\Tests\Support\RollbackKernelTestCase;
use Symfony\Component\Uid\Uuid;

final class ArticleListRepositoryTest extends RollbackKernelTestCase
{
    public function testThePageHoldsOnlyTheEnabledSourcesOfTheAccountNewestFirstWithKeysetPaging(): void
    {
        $user = $this->user();
        $other = $this->user();
        $feed = $this->feed();
        $disabledFeed = $this->feed('disabled');
        $foreign = $this->feed('foreign');
        $this->subscribe($user, $feed);
        $this->subscribe($user, $disabledFeed)->disable();
        $this->subscribe($other, $foreign);
        $articles = [];
        foreach (['a', 'b', 'c'] as $name) {
            $articles[$name] = $this->article($feed, $name);
        }
        $this->article($disabledFeed, 'hidden');
        $this->article($foreign, 'foreign');
        $this->em->flush();
        foreach (['a' => '2030-01-01 00:00:01', 'b' => '2030-01-01 00:00:02', 'c' => '2030-01-01 00:00:03'] as $name => $at) {
            $this->db->executeStatement('UPDATE article SET collected_at = ? WHERE id = ?', [$at, $articles[$name]->getId()->toBinary()]);
        }
        $repository = self::getContainer()->get(ArticleRepository::class);

        $first = $repository->findPage($user, new ArticleListFilter(), 2);
        self::assertSame([(string) $articles['c']->getId(), (string) $articles['b']->getId()], array_map(static fn (array $row): string => (string) $row['id'], $first));

        $second = $repository->findPage($user, new ArticleListFilter(cursor: ['collectedAt' => new \DateTimeImmutable('2030-01-01 00:00:02'), 'id' => $articles['b']->getId()]), 10);
        self::assertSame([(string) $articles['a']->getId()], array_map(static fn (array $row): string => (string) $row['id'], $second));
    }

    public function testFiltersNarrowTheListByStateSourceCategorySearchAndDates(): void
    {
        $user = $this->user();
        $feed = $this->feed('filtered');
        $subscription = $this->subscribe($user, $feed);
        $category = new Category($user, 'Dev');
        $this->em->persist($category);
        $subscription->assignCategory($category);
        $read = $this->article($feed, 'read');
        $favorite = $this->article($feed, 'favorite');
        $plain = $this->article($feed, 'plain');
        $readState = new ArticleState($user, $read);
        $readState->markRead();
        $favoriteState = new ArticleState($user, $favorite);
        $favoriteState->setFavorite(true);
        $this->em->persist($readState);
        $this->em->persist($favoriteState);
        $this->em->flush();
        $repository = self::getContainer()->get(ArticleRepository::class);
        $ids = static fn (array $rows): array => array_map(static fn (array $row): string => (string) $row['id'], $rows);

        self::assertEqualsCanonicalizing([(string) $favorite->getId(), (string) $plain->getId()], $ids($repository->findPage($user, new ArticleListFilter(read: false), 10)));
        self::assertSame([(string) $read->getId()], $ids($repository->findPage($user, new ArticleListFilter(read: true), 10)));
        self::assertSame([(string) $favorite->getId()], $ids($repository->findPage($user, new ArticleListFilter(favorite: true), 10)));
        self::assertCount(3, $repository->findPage($user, new ArticleListFilter(subscription: $subscription->getId(), category: $category->getId()), 10));
        self::assertSame([], $repository->findPage($user, new ArticleListFilter(subscription: Uuid::v7()), 10));
        self::assertSame([(string) $plain->getId()], $ids($repository->findPage($user, new ArticleListFilter(search: 'ARTICLE PLAIN'), 10)));
        self::assertSame([], $repository->findPage($user, new ArticleListFilter(from: new \DateTimeImmutable('+1 day')), 10));
        self::assertCount(3, $repository->findPage($user, new ArticleListFilter(to: new \DateTimeImmutable('+1 day')), 10));
    }
}
