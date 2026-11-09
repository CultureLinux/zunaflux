<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261109100000 extends AbstractMigration
{
    public function getDescription(): string { return 'Stores each user Mastodon account and encrypted OAuth access token.'; }
    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE mastodon_account (id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)', user_id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)', instance_url VARCHAR(2048) NOT NULL, mastodon_account_id VARCHAR(255) NOT NULL, acct VARCHAR(255) NOT NULL, display_name VARCHAR(255) NOT NULL, avatar_url VARCHAR(2048) DEFAULT NULL, access_token_encrypted LONGTEXT NOT NULL, updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', UNIQUE INDEX uniq_mastodon_account_user (user_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('ALTER TABLE mastodon_account ADD CONSTRAINT fk_mastodon_account_user FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
    }
    public function down(Schema $schema): void { $this->addSql('DROP TABLE mastodon_account'); }
}
