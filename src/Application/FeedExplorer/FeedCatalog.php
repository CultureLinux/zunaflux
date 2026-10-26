<?php
declare(strict_types=1);
namespace App\Application\FeedExplorer;
use Symfony\Component\Yaml\Yaml;
final readonly class FeedCatalog {
 public function __construct(private string $projectDir) {}
 /** @return list<array<string,mixed>> */ public function all(): array { $data=Yaml::parseFile($this->projectDir.'/resources/feed-explorer/feeds.yaml'); return $data['feeds']??[]; }
 /** @return array<string,list<array<string,mixed>>> */ public function grouped(?string $language = null, ?string $category = null): array { $out=[]; foreach($this->all() as $feed) { if ((null !== $language && ($feed['language'] ?? null) !== $language) || (null !== $category && ($feed['category'] ?? null) !== $category)) continue; $out[$feed['category']][]=$feed; } ksort($out); return $out; }
 /** @return array<string,mixed>|null */ public function find(string $id): ?array { foreach($this->all() as $feed)if($feed['id']===$id)return $feed; return null; }
}
