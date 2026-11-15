<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261114120000 extends AbstractMigration
{
    public function getDescription(): string { return 'Adds personal notification schedules and a persistent digest queue.'; }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE user_preference ADD notification_mode VARCHAR(12) NOT NULL DEFAULT 'interval', ADD notification_interval INT NOT NULL DEFAULT 1, ADD notification_times JSON NOT NULL DEFAULT '[8]'");
        $this->addSql("ALTER TABLE push_subscription ADD digest_cursor INT NOT NULL DEFAULT 0, ADD digest_sent_timestamp BIGINT DEFAULT NULL");
        $this->addSql("CREATE TABLE notification_pending (id INT AUTO_INCREMENT NOT NULL, subscription_id BINARY(16) NOT NULL, event_id BINARY(16) NOT NULL, article_count INT NOT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX uniq_notification_event (subscription_id, event_id), INDEX idx_notification_created (created_at), PRIMARY KEY(id), CONSTRAINT fk_notification_subscription FOREIGN KEY (subscription_id) REFERENCES subscription (id) ON DELETE CASCADE) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE notification_pending');
        $this->addSql('ALTER TABLE push_subscription DROP digest_cursor, DROP digest_sent_timestamp');
        $this->addSql('ALTER TABLE user_preference DROP notification_mode, DROP notification_interval, DROP notification_times');
    }
}
