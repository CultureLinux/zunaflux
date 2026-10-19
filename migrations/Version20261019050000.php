<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261019050000 extends AbstractMigration
{
    public function getDescription(): string { return 'Adds article fulltext search, per-user preferences and saved filters.'; }
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE article ADD FULLTEXT INDEX idx_article_search (title, summary)');
        $this->addSql('CREATE TABLE user_preference (id BINARY(16) NOT NULL COMMENT \'(DC2Type:uuid)\', user_id BINARY(16) NOT NULL COMMENT \'(DC2Type:uuid)\', theme VARCHAR(10) NOT NULL, density VARCHAR(12) NOT NULL, font_scale VARCHAR(10) NOT NULL, accent VARCHAR(20) NOT NULL, read_mode VARCHAR(12) NOT NULL, sidebar_collapsed TINYINT(1) NOT NULL, timezone VARCHAR(64) NOT NULL, UNIQUE INDEX uniq_user_preference_user (user_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE saved_filter (id BINARY(16) NOT NULL COMMENT \'(DC2Type:uuid)\', user_id BINARY(16) NOT NULL COMMENT \'(DC2Type:uuid)\', name VARCHAR(100) NOT NULL, filters JSON NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX uniq_saved_filter_user_name (user_id, name), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE user_preference ADD CONSTRAINT fk_user_preference_user FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE saved_filter ADD CONSTRAINT fk_saved_filter_user FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
    }
    public function down(Schema $schema): void { $this->addSql('DROP TABLE saved_filter'); $this->addSql('DROP TABLE user_preference'); $this->addSql('ALTER TABLE article DROP INDEX idx_article_search'); }
}
