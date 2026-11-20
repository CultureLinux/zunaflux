<?php

declare(strict_types=1);

namespace App\Application\Reading;

use Symfony\Component\Uid\Uuid;

/** Criteria of the reading list; null means "no restriction". */
final readonly class ArticleListFilter
{
    /**
     * @param array{collectedAt: \DateTimeImmutable, id: Uuid}|null $cursor keyset position (last item of the previous page)
     */
    public function __construct(
        public ?array $cursor = null,
        public string $search = '',
        public ?Uuid $subscription = null,
        public ?Uuid $category = null,
        public ?bool $read = null,
        public ?bool $favorite = null,
        public ?bool $saved = null,
        public ?\DateTimeImmutable $from = null,
        public ?\DateTimeImmutable $to = null,
    ) {
    }
}
