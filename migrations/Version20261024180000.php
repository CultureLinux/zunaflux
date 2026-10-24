<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261024180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds a per-feed collection backoff deadline.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE feed ADD next_collection_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE feed DROP next_collection_at');
    }
}
