<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261115120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds a transactional outbox for collection notifications.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE notification_outbox (event_id BINARY(16) NOT NULL, feed_id BINARY(16) NOT NULL, article_count INT NOT NULL, created_at DATETIME NOT NULL, INDEX idx_outbox_created (created_at, event_id), INDEX idx_outbox_feed (feed_id, created_at, event_id), PRIMARY KEY(event_id), CONSTRAINT fk_outbox_feed FOREIGN KEY (feed_id) REFERENCES feed (id) ON DELETE CASCADE, CONSTRAINT chk_outbox_article_count CHECK (article_count > 0)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE notification_outbox');
    }
}
