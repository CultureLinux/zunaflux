<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261020120000 extends AbstractMigration
{
    public function getDescription(): string { return 'Store Web Push subscriptions per user and device.'; }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE push_subscription (id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)', user_id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)', endpoint LONGTEXT NOT NULL, endpoint_hash BINARY(32) NOT NULL, public_key VARCHAR(255) NOT NULL, auth_secret VARCHAR(255) NOT NULL, UNIQUE INDEX uniq_push_endpoint_hash (endpoint_hash), INDEX idx_push_user (user_id), PRIMARY KEY(id), CONSTRAINT fk_push_user FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
    }

    public function down(Schema $schema): void { $this->addSql('DROP TABLE push_subscription'); }
}
