<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'mastodon_account')]
#[ORM\UniqueConstraint(name: 'uniq_mastodon_account_user', columns: ['user_id'])]
final class MastodonAccount
{
    #[ORM\Id] #[ORM\Column(type: 'uuid')] private Uuid $id;
    #[ORM\OneToOne] #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')] private User $user;
    #[ORM\Column(name: 'instance_url', length: 2048)] private string $instanceUrl;
    #[ORM\Column(name: 'mastodon_account_id', length: 255)] private string $mastodonAccountId;
    #[ORM\Column(length: 255)] private string $acct;
    #[ORM\Column(name: 'display_name', length: 255)] private string $displayName;
    #[ORM\Column(name: 'avatar_url', length: 2048, nullable: true)] private ?string $avatarUrl;
    #[ORM\Column(name: 'access_token_encrypted', type: 'text')] private string $accessTokenEncrypted;
    #[ORM\Column(name: 'updated_at')] private \DateTimeImmutable $updatedAt;

    public function __construct(User $user, string $instanceUrl, string $accountId, string $acct, string $displayName, ?string $avatarUrl, string $encryptedToken)
    {
        $this->id = Uuid::v7(); $this->user = $user; $this->replace($instanceUrl, $accountId, $acct, $displayName, $avatarUrl, $encryptedToken);
    }
    public function getUser(): User { return $this->user; }
    public function getInstanceUrl(): string { return $this->instanceUrl; }
    public function getAcct(): string { return $this->acct; }
    public function getDisplayName(): string { return $this->displayName; }
    public function getAvatarUrl(): ?string { return $this->avatarUrl; }
    public function getAccessTokenEncrypted(): string { return $this->accessTokenEncrypted; }
    public function replace(string $instanceUrl, string $accountId, string $acct, string $displayName, ?string $avatarUrl, string $encryptedToken): void { $this->instanceUrl = $instanceUrl; $this->mastodonAccountId = $accountId; $this->acct = $acct; $this->displayName = $displayName; $this->avatarUrl = $avatarUrl; $this->accessTokenEncrypted = $encryptedToken; $this->updatedAt = new \DateTimeImmutable(); }
}
