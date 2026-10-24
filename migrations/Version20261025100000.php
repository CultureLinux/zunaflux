<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261025100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Tracks when a feed incident first recovered before automatic cleanup.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE feed_incident ADD recovered_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE feed_incident DROP recovered_at');
    }
}
