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

    public function __construct(User $user, string $endpoint, string $publicKey, string $authSecret)
    {
        $this->id = Uuid::v7();
        $this->endpoint = $endpoint;
        $this->endpointHash = hash('sha256', $endpoint, true);
        $this->update($user, $publicKey, $authSecret);
    }

    public function update(User $user, string $publicKey, string $authSecret): void
    {
        $this->user = $user;
        $this->publicKey = $publicKey;
        $this->authSecret = $authSecret;
    }

    public function getUser(): User { return $this->user; }
    public function getEndpoint(): string { return $this->endpoint; }
    public function getPublicKey(): string { return $this->publicKey; }
    public function getAuthSecret(): string { return $this->authSecret; }
}
