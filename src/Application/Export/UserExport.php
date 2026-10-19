<?php

declare(strict_types=1);

namespace App\Application\Export;

use App\Entity\ArticleState;
use App\Entity\Category;
use App\Entity\ReadingDailyStat;
use App\Entity\SavedFilter;
use App\Entity\Subscription;
use App\Entity\User;
use App\Entity\UserPreference;
use App\Infrastructure\Export\StreamingZipArchive;
use Doctrine\ORM\EntityManagerInterface;

final readonly class UserExport
{
    public function __construct(private EntityManagerInterface $entityManager, private StreamingZipArchive $archive)
    {
    }

    /** @return array{filename: string, contents: string} */
    public function create(User $user): array
    {
        $repository = fn (string $class) => $this->entityManager->getRepository($class);
        $preference = $repository(UserPreference::class)->findOneBy(['user' => $user]);
        $categories = $repository(Category::class)->findBy(['user' => $user], ['name' => 'ASC']);
        $subscriptions = $repository(Subscription::class)->findBy(['user' => $user]);
        $filters = $repository(SavedFilter::class)->findBy(['user' => $user]);
        $states = $repository(ArticleState::class)->findBy(['user' => $user]);
        $stats = $repository(ReadingDailyStat::class)->findBy(['user' => $user]);

        $entries = [
            'manifest.json' => $this->json(['formatVersion' => 1, 'exportedAt' => (new \DateTimeImmutable())->format(DATE_ATOM), 'application' => 'Zunaflux']),
            'profile.json' => $this->json(['email' => $user->getUserIdentifier()]),
            'preferences.json' => $this->json($preference instanceof UserPreference ? ['theme' => $preference->getTheme(), 'density' => $preference->getDensity(), 'fontScale' => $preference->getFontScale(), 'accent' => $preference->getAccent(), 'readMode' => $preference->getReadMode(), 'sidebarCollapsed' => $preference->isSidebarCollapsed(), 'timezone' => $preference->getTimezone()] : null),
            'categories.json' => $this->json(array_map(static fn (Category $category): array => ['id' => (string) $category->getId(), 'name' => $category->getName()], $categories)),
            'subscriptions.json' => $this->json(array_map(static fn (Subscription $subscription): array => ['feedUrl' => $subscription->getFeed()->getCanonicalUrl(), 'title' => $subscription->getDisplayTitle(), 'enabled' => $subscription->isEnabled(), 'categoryId' => $subscription->getCategory() ? (string) $subscription->getCategory()->getId() : null], $subscriptions)),
            'filters.json' => $this->json(array_map(static fn (SavedFilter $filter): array => ['name' => $filter->getName(), 'criteria' => $filter->getFilters()], $filters)),
            'states.json' => $this->json(array_map(static fn (ArticleState $state): array => ['feedUrl' => $state->getArticle()->getFeed()->getCanonicalUrl(), 'articleUrl' => $state->getArticle()->getUrl(), 'isRead' => $state->isRead(), 'readAt' => $state->getReadAt()?->format(DATE_ATOM), 'isFavorite' => $state->isFavorite(), 'isSaved' => $state->isSaved()], $states)),
            'statistics.json' => $this->json(array_map(static fn (ReadingDailyStat $stat): array => ['date' => $stat->getStatDate()->format('Y-m-d'), 'category' => $stat->getCategorySnapshot(), 'firstReads' => $stat->getFirstReads()], $stats)),
            'README.txt' => "Format d’export Zunaflux v1.\nLes états sont fournis à titre d’archive ; leur réimportation complète n’est pas prise en charge en v1.\n",
        ];

        return ['filename' => 'zunaflux-export-'.(new \DateTimeImmutable())->format('Ymd').'.zip', 'contents' => $this->archive->create($entries)];
    }

    public function createOpml(User $user): string
    {
        $outlines = '';
        foreach ($this->entityManager->getRepository(Subscription::class)->findBy(['user' => $user]) as $subscription) {
            $outlines .= sprintf("    <outline type=\"rss\" text=\"%s\" xmlUrl=\"%s\" />\n", htmlspecialchars($subscription->getDisplayTitle(), \ENT_XML1 | \ENT_QUOTES, 'UTF-8'), htmlspecialchars($subscription->getFeed()->getCanonicalUrl(), \ENT_XML1 | \ENT_QUOTES, 'UTF-8'));
        }

        return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<opml version=\"2.0\"><head><title>Zunaflux</title></head><body>\n".$outlines."</body></opml>\n";
    }

    private function json(mixed $value): string
    {
        return json_encode($value, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)."\n";
    }
}
