<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Article;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Domain\Feed\ParsedFeedItem;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** Boots the kernel and runs every test inside a transaction that is always rolled back. */
abstract class RollbackKernelTestCase extends KernelTestCase
{
    protected EntityManagerInterface $em;

    protected Connection $db;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->db = $this->em->getConnection();
        $this->db->beginTransaction();
    }

    protected function tearDown(): void
    {
        while ($this->db->isTransactionActive()) {
            $this->db->rollBack();
        }
        parent::tearDown();
    }

    protected function user(string $prefix = 'user'): User
    {
        $user = new User($prefix.'-'.bin2hex(random_bytes(6)).'@example.test');
        $this->em->persist($user);

        return $user;
    }

    protected function feed(string $prefix = 'feed'): Feed
    {
        $feed = new Feed('https://example.test/'.$prefix.'-'.bin2hex(random_bytes(6)));
        $this->em->persist($feed);

        return $feed;
    }

    protected function subscribe(User $user, Feed $feed): Subscription
    {
        $subscription = new Subscription($user, $feed);
        $this->em->persist($subscription);

        return $subscription;
    }

    protected function article(Feed $feed, ?string $identity = null): Article
    {
        $identity ??= bin2hex(random_bytes(6));
        $article = new Article($feed, new ParsedFeedItem($identity, 'Article '.$identity, 'https://example.test/a/'.$identity, 'Summary', null, null));
        $this->em->persist($article);

        return $article;
    }

    protected function scalar(string $sql, array $params = []): int
    {
        return (int) $this->db->fetchOne($sql, $params);
    }
}
