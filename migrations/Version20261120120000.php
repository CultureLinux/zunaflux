<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261120120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds idx_article_collected for retention purge and collection-order reads.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE article ADD INDEX idx_article_collected (collected_at, id), ALGORITHM=INPLACE, LOCK=NONE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE article DROP INDEX idx_article_collected');
    }
}
