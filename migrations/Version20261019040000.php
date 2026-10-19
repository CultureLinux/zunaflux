<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261019040000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds per-user reading, favorite and saved states for shared articles.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE article_state (id BINARY(16) NOT NULL COMMENT \'(DC2Type:uuid)\', user_id BINARY(16) NOT NULL COMMENT \'(DC2Type:uuid)\', article_id BINARY(16) NOT NULL COMMENT \'(DC2Type:uuid)\', is_read TINYINT(1) NOT NULL, read_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', is_favorite TINYINT(1) NOT NULL, is_saved TINYINT(1) NOT NULL, updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX uniq_article_state_user_article (user_id, article_id), INDEX idx_article_state_user_read (user_id, is_read), INDEX idx_article_state_user_favorite (user_id, is_favorite), INDEX idx_article_state_user_saved (user_id, is_saved), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE article_state ADD CONSTRAINT fk_article_state_user FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE article_state ADD CONSTRAINT fk_article_state_article FOREIGN KEY (article_id) REFERENCES article (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE article_state');
    }
}
