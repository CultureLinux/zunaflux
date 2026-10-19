<?php

declare(strict_types=1);

namespace App\Entity;

use App\Domain\Feed\ParsedFeedItem;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'article')]
#[ORM\UniqueConstraint(name: 'uniq_article_feed_identity_hash', columns: ['feed_id', 'identity_hash'])]
final class Article
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'feed_id', nullable: false, onDelete: 'CASCADE')]
    private Feed $feed;
    #[ORM\Column(length: 2048)]
    private string $identity;
    #[ORM\Column(name: 'identity_hash', type: 'binary', length: 32, options: ['fixed' => true])]
    private string $identityHash;
    #[ORM\Column(length: 500)]
    private string $title;
    #[ORM\Column(length: 2048, nullable: true)]
    private ?string $url;
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $summary;
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $content;
    #[ORM\Column(name: 'published_at', nullable: true)]
    private ?\DateTimeImmutable $publishedAt;
    #[ORM\Column(name: 'collected_at')]
    private \DateTimeImmutable $collectedAt;

    public function __construct(Feed $feed, ParsedFeedItem $item)
    {
        $this->id = Uuid::v7(); $this->feed = $feed; $this->identity = mb_substr($item->identity, 0, 2048);
        $this->identityHash = hash('sha256', $item->identity, true); $this->title = mb_substr($item->title, 0, 500);
        $this->url = $item->url ? mb_substr($item->url, 0, 2048) : null; $this->summary = $item->summary;
        $this->content = $item->content; $this->publishedAt = $item->publishedAt; $this->collectedAt = new \DateTimeImmutable();
    }
}
