<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'feed')]
#[ORM\UniqueConstraint(name: 'uniq_feed_canonical_url_hash', columns: ['canonical_url_hash'])]
final class Feed
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(name: 'canonical_url', length: 2048)]
    private string $canonicalUrl;

    #[ORM\Column(name: 'canonical_url_hash', type: 'binary', length: 32, options: ['fixed' => true])]
    private string $canonicalUrlHash;

    #[ORM\Column(name: 'site_url', length: 2048, nullable: true)]
    private ?string $siteUrl = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $title = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $etag = null;

    #[ORM\Column(name: 'last_modified', length: 255, nullable: true)]
    private ?string $lastModified = null;

    #[ORM\Column(length: 20)]
    private string $status = 'active';

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $url)
    {
        $this->id = Uuid::v7();
        $this->canonicalUrl = self::canonicalizeUrl($url);
        $this->canonicalUrlHash = hash('sha256', $this->canonicalUrl, true);
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCanonicalUrl(): string
    {
        return $this->canonicalUrl;
    }

    public function getCanonicalUrlHash(): string
    {
        return $this->canonicalUrlHash;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function refreshMetadata(?string $title, ?string $siteUrl): void
    {
        $this->title = null === $title ? null : mb_substr(trim($title), 0, 255);
        $this->siteUrl = null === $siteUrl ? null : mb_substr(trim($siteUrl), 0, 2048);
    }

    public function recordFetchValidators(?string $etag, ?string $lastModified): void
    {
        $this->etag = null === $etag ? null : mb_substr($etag, 0, 255);
        $this->lastModified = null === $lastModified ? null : mb_substr($lastModified, 0, 255);
    }

    public function getEtag(): ?string
    {
        return $this->etag;
    }

    public function getLastModified(): ?string
    {
        return $this->lastModified;
    }

    private static function canonicalizeUrl(string $url): string
    {
        $parts = parse_url(trim($url));

        if (false === $parts || !isset($parts['scheme'], $parts['host'])) {
            throw new \InvalidArgumentException('A feed URL must be an absolute HTTP(S) URL.');
        }

        $scheme = strtolower($parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new \InvalidArgumentException('A feed URL must use HTTP or HTTPS.');
        }

        if (isset($parts['user'], $parts['pass'])) {
            throw new \InvalidArgumentException('A feed URL must not contain credentials.');
        }

        $host = strtolower($parts['host']);
        $port = $parts['port'] ?? null;
        $authority = str_contains($host, ':') ? '['.$host.']' : $host;
        if (null !== $port && !(('http' === $scheme && 80 === $port) || ('https' === $scheme && 443 === $port))) {
            $authority .= ':'.$port;
        }

        $path = $parts['path'] ?? '/';
        if ('' === $path) {
            $path = '/';
        }

        return $scheme.'://'.$authority.$path.(isset($parts['query']) ? '?'.$parts['query'] : '');
    }
}
