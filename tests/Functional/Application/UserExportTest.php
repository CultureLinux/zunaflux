<?php

declare(strict_types=1);

namespace App\Tests\Functional\Application;

use App\Application\Export\UserExport;
use App\Entity\ArticleState;
use App\Entity\Category;
use App\Entity\SavedFilter;
use App\Tests\Support\RollbackKernelTestCase;

final class UserExportTest extends RollbackKernelTestCase
{
    public function testTheArchiveContainsOnlyTheDataOfTheExportedAccount(): void
    {
        $owner = $this->user('owner');
        $other = $this->user('other');
        $feed = $this->feed('mine');
        $otherFeed = $this->feed('theirs');
        $article = $this->article($feed, 'mine-1');
        $subscription = $this->subscribe($owner, $feed);
        $this->subscribe($other, $otherFeed);
        $category = new Category($owner, 'Lectures');
        $this->em->persist($category);
        $subscription->assignCategory($category);
        $this->em->persist(new SavedFilter($owner, 'Non lus', ['read' => '0']));
        $this->em->persist(new SavedFilter($other, 'Secret', ['q' => 'x']));
        $state = new ArticleState($owner, $article);
        $state->markRead();
        $this->em->persist($state);
        $this->em->flush();

        $export = self::getContainer()->get(UserExport::class)->create($owner);

        self::assertMatchesRegularExpression('/^zunaflux-export-\d{8}\.zip$/', $export['filename']);
        $path = tempnam(sys_get_temp_dir(), 'zunaflux-export');
        file_put_contents($path, $export['contents']);
        $zip = new \ZipArchive();
        try {
            self::assertTrue($zip->open($path));
            foreach (['manifest.json', 'profile.json', 'preferences.json', 'categories.json', 'subscriptions.json', 'filters.json', 'states.json', 'statistics.json', 'README.txt'] as $entry) {
                self::assertNotFalse($zip->getFromName($entry), $entry.' is missing from the archive');
            }
            self::assertSame($owner->getUserIdentifier(), json_decode((string) $zip->getFromName('profile.json'), true)['email']);
            $subscriptions = json_decode((string) $zip->getFromName('subscriptions.json'), true);
            self::assertSame([$feed->getCanonicalUrl()], array_column($subscriptions, 'feedUrl'));
            self::assertSame(['Non lus'], array_column(json_decode((string) $zip->getFromName('filters.json'), true), 'name'));
            self::assertSame(['Lectures'], array_column(json_decode((string) $zip->getFromName('categories.json'), true), 'name'));
            $states = json_decode((string) $zip->getFromName('states.json'), true);
            self::assertCount(1, $states);
            self::assertTrue($states[0]['isRead']);
            $everything = '';
            for ($i = 0; $i < $zip->numFiles; ++$i) {
                $everything .= (string) $zip->getFromIndex($i);
            }
            self::assertStringNotContainsString($otherFeed->getCanonicalUrl(), $everything);
            self::assertStringNotContainsString($other->getUserIdentifier(), $everything);
            self::assertStringNotContainsString('Secret', $everything);
        } finally {
            $zip->close();
            unlink($path);
        }
    }

    public function testTheOpmlGroupsSubscriptionsByCategoryAndEscapesTitles(): void
    {
        $user = $this->user();
        $plain = $this->feed('plain');
        $tagged = $this->feed('tagged');
        $this->subscribe($user, $plain);
        $subscription = $this->subscribe($user, $tagged);
        $category = new Category($user, 'Dev & Ops');
        $this->em->persist($category);
        $subscription->assignCategory($category);
        $this->em->flush();

        $opml = self::getContainer()->get(UserExport::class)->createOpml($user);

        $document = new \DOMDocument();
        self::assertTrue($document->loadXML($opml));
        $xpath = new \DOMXPath($document);
        self::assertSame(1, $xpath->query('/opml/body/outline[@xmlUrl="'.$plain->getCanonicalUrl().'"]')->length);
        self::assertSame(1, $xpath->query('/opml/body/outline[@text="Dev & Ops"]/outline[@xmlUrl="'.$tagged->getCanonicalUrl().'"]')->length);
    }
}
