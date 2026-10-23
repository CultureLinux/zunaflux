<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'push_subscription')]
#[ORM\UniqueConstraint(name: 'uniq_push_endpoint_hash', columns: ['endpoint_hash'])]
final class PushSubscription
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: 'text')]
    private string $endpoint;

    #[ORM\Column(name: 'endpoint_hash', type: 'binary', length: 32, options: ['fixed' => true])]
    private string $endpointHash;

    #[ORM\Column(name: 'public_key', length: 255)]
    private string $publicKey;

    #[ORM\Column(name: 'auth_secret', length: 255)]
    private string $authSecret;

    #[ORM\Column(name: 'device_label', length: 80, options: ['default' => 'Navigateur inconnu'])]
    private string $deviceLabel = 'Navigateur inconnu';

    #[ORM\Column(name: 'last_result', length: 20, nullable: true)]
    private ?string $lastResult = null;

    #[ORM\Column(name: 'last_result_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastResultAt = null;

    public function __construct(User $user, string $endpoint, string $publicKey, string $authSecret)
    {
        $this->id = Uuid::v7();
        $this->endpoint = $endpoint;
        $this->endpointHash = hash('sha256', $endpoint, true);
        $this->update($user, $publicKey, $authSecret);
    }

    public function update(User $user, string $publicKey, string $authSecret): void
    {
        if (!$this->user->getId()->equals($user->getId())) {
            $this->lastResult = null;
            $this->lastResultAt = null;
        }
        $this->user = $user;
        $this->publicKey = $publicKey;
        $this->authSecret = $authSecret;
    }

    public function getUser(): User { return $this->user; }
    public function getId(): Uuid { return $this->id; }
    public function getEndpoint(): string { return $this->endpoint; }
    public function getPublicKey(): string { return $this->publicKey; }
    public function getAuthSecret(): string { return $this->authSecret; }
    public function getDeviceLabel(): string { return $this->deviceLabel; }
    public function getLastResult(): ?string { return $this->lastResult; }
    public function getLastResultAt(): ?\DateTimeImmutable { return $this->lastResultAt; }
    public function setDeviceLabel(string $label): void { $this->deviceLabel = $label; }
    public function recordResult(string $result): void { $this->lastResult = $result; $this->lastResultAt = new \DateTimeImmutable(); }
}
