<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261019020000 extends AbstractMigration
{
    public function getDescription(): string { return 'Adds collected feed articles, with idempotent per-feed identities.'; }
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE article (id BINARY(16) NOT NULL COMMENT \'(DC2Type:uuid)\', feed_id BINARY(16) NOT NULL COMMENT \'(DC2Type:uuid)\', identity VARCHAR(2048) NOT NULL, identity_hash BINARY(32) NOT NULL, title VARCHAR(500) NOT NULL, url VARCHAR(2048) DEFAULT NULL, summary LONGTEXT DEFAULT NULL, content LONGTEXT DEFAULT NULL, published_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', collected_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX uniq_article_feed_identity_hash (feed_id, identity_hash), INDEX idx_article_feed_published (feed_id, published_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE article ADD CONSTRAINT fk_article_feed FOREIGN KEY (feed_id) REFERENCES feed (id) ON DELETE CASCADE');
    }
    public function down(Schema $schema): void { $this->addSql('DROP TABLE article'); }
}
