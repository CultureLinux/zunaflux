<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261023120000 extends AbstractMigration
{
    public function getDescription(): string { return 'Add per-device notification diagnostics and expired subscription notices.'; }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE push_subscription ADD device_label VARCHAR(80) NOT NULL DEFAULT 'Navigateur inconnu', ADD last_result VARCHAR(20) DEFAULT NULL, ADD last_result_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)'");
        $this->addSql("CREATE TABLE push_expired_notice (id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)', user_id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)', device_label VARCHAR(80) NOT NULL, expired_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', INDEX idx_push_expired_user (user_id), PRIMARY KEY(id), CONSTRAINT fk_push_expired_user FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE push_expired_notice');
        $this->addSql('ALTER TABLE push_subscription DROP device_label, DROP last_result, DROP last_result_at');
    }
}
