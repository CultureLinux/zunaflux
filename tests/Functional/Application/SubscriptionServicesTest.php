<?php

declare(strict_types=1);

namespace App\Tests\Functional\Application;

use App\Application\Subscription\CreateCategory;
use App\Application\Subscription\ImportOpml;
use App\Entity\Category;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Tests\Support\RollbackKernelTestCase;

final class SubscriptionServicesTest extends RollbackKernelTestCase
{
    public function testACategoryIsCreatedOncePerUserAndName(): void
    {
        $owner = $this->user();
        $other = $this->user();
        $this->em->flush();
        $service = self::getContainer()->get(CreateCategory::class);

        $first = $service->create($owner, 'Tech');
        $again = $service->create($owner, '  Tech  ');
        $foreign = $service->create($other, 'Tech');

        self::assertSame($first, $again);
        self::assertNotSame($first, $foreign);
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM category WHERE user_id = ?', [$owner->getId()->toBinary()]));
    }

    public function testImportReportsImportedDuplicateAndFailedEntriesAndAssignsCategories(): void
    {
        $user = $this->user();
        $existing = $this->feed('known');
        $this->subscribe($user, $existing);
        $this->em->flush();
        $suffix = bin2hex(random_bytes(6));
        $opml = <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <opml version="2.0"><body>
              <outline text="News">
                <outline type="rss" text="New" xmlUrl="https://example.test/new-{$suffix}"/>
              </outline>
              <outline type="rss" text="Known" xmlUrl="{$existing->getCanonicalUrl()}"/>
              <outline type="rss" text="Broken" xmlUrl="ftp://example.test/feed"/>
            </body></opml>
            XML;

        $result = self::getContainer()->get(ImportOpml::class)->import($user, $opml);

        self::assertSame(['imported' => 1, 'duplicate' => 1, 'failed' => 1], $result);
        $category = $this->em->getRepository(Category::class)->findOneBy(['user' => $user, 'name' => 'News']);
        self::assertInstanceOf(Category::class, $category);
        $feed = $this->em->getRepository(Feed::class)->findOneBy(['canonicalUrlHash' => (new Feed('https://example.test/new-'.$suffix))->getCanonicalUrlHash()]);
        $subscription = $this->em->getRepository(Subscription::class)->findOneBy(['user' => $user, 'feed' => $feed]);
        self::assertSame($category, $subscription?->getCategory());
        self::assertSame(2, $this->scalar('SELECT COUNT(*) FROM subscription WHERE user_id = ?', [$user->getId()->toBinary()]));
    }

    public function testImportRefusesDocumentsWithADoctype(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        self::getContainer()->get(ImportOpml::class)->import($this->user(), '<?xml version="1.0"?><!DOCTYPE opml><opml version="2.0"><body/></opml>');
    }
}
