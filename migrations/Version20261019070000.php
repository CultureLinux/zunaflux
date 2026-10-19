<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261019070000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds durable first-read events and daily reading statistics for maintenance.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE reading_daily_stat (id BINARY(16) NOT NULL COMMENT \'(DC2Type:uuid)\', user_id BINARY(16) NOT NULL COMMENT \'(DC2Type:uuid)\', stat_date DATE NOT NULL COMMENT \'(DC2Type:date_immutable)\', category_snapshot VARCHAR(100) NOT NULL DEFAULT \'\', first_reads INT UNSIGNED NOT NULL DEFAULT 0, UNIQUE INDEX uniq_reading_daily_stat_dimension (user_id, stat_date, category_snapshot), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE first_read_event (id BINARY(16) NOT NULL COMMENT \'(DC2Type:uuid)\', user_id BINARY(16) NOT NULL COMMENT \'(DC2Type:uuid)\', feed_id BINARY(16) NOT NULL COMMENT \'(DC2Type:uuid)\', article_identity_hash BINARY(32) NOT NULL, first_read_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX uniq_first_read_event_identity (user_id, feed_id, article_identity_hash), INDEX idx_first_read_event_user_date (user_id, first_read_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE reading_daily_stat ADD CONSTRAINT fk_reading_daily_stat_user FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE first_read_event ADD CONSTRAINT fk_first_read_event_user FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE first_read_event ADD CONSTRAINT fk_first_read_event_feed FOREIGN KEY (feed_id) REFERENCES feed (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE first_read_event');
        $this->addSql('DROP TABLE reading_daily_stat');
    }
}
