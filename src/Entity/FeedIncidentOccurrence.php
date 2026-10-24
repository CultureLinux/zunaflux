<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'feed_incident_occurrence')]
final class FeedIncidentOccurrence
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'incident_id', nullable: false, onDelete: 'CASCADE')]
    private FeedIncident $incident;

    #[ORM\Column(length: 255)]
    private string $message;

    #[ORM\Column(name: 'occurred_at')]
    private \DateTimeImmutable $occurredAt;

    public function __construct(FeedIncident $incident, \Throwable $error, ?\DateTimeImmutable $occurredAt = null)
    {
        $this->id = Uuid::v7();
        $this->incident = $incident;
        $this->message = mb_substr($error->getMessage(), 0, 255);
        $this->occurredAt = $occurredAt ?? new \DateTimeImmutable();
    }

    public function getIncident(): FeedIncident { return $this->incident; }
    public function getMessage(): string { return $this->message; }
    public function getOccurredAt(): \DateTimeImmutable { return $this->occurredAt; }
}
