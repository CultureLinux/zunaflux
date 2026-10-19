<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'reading_daily_stat')]
#[ORM\UniqueConstraint(name: 'uniq_reading_daily_stat_dimension', columns: ['user_id', 'stat_date', 'category_snapshot'])]
final class ReadingDailyStat
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(name: 'stat_date', type: 'date_immutable')]
    private \DateTimeImmutable $statDate;

    #[ORM\Column(name: 'category_snapshot', length: 100)]
    private string $categorySnapshot;

    #[ORM\Column(name: 'first_reads')]
    private int $firstReads = 0;

    public function __construct(User $user, \DateTimeImmutable $statDate, ?string $categorySnapshot)
    {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->statDate = $statDate->setTime(0, 0);
        $this->categorySnapshot = $categorySnapshot ?? '';
    }

    public function increment(): void
    {
        ++$this->firstReads;
    }

    public function getFirstReads(): int { return $this->firstReads; }
    public function getCategorySnapshot(): string { return $this->categorySnapshot; }
    public function getStatDate(): \DateTimeImmutable { return $this->statDate; }
}
