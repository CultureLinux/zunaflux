<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'push_expired_notice')]
final class PushExpiredNotice
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 80)]
    private string $deviceLabel;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $expiredAt;

    public function __construct(PushSubscription $subscription)
    {
        $this->id = Uuid::v7();
        $this->user = $subscription->getUser();
        $this->deviceLabel = $subscription->getDeviceLabel();
        $this->expiredAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid { return $this->id; }
    public function getDeviceLabel(): string { return $this->deviceLabel; }
    public function getExpiredAt(): \DateTimeImmutable { return $this->expiredAt; }
}
