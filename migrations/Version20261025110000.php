<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261025110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Stores every feed incident occurrence for administration history.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE feed_incident_occurrence (id BINARY(16) NOT NULL COMMENT \'(DC2Type:uuid)\', incident_id BINARY(16) NOT NULL COMMENT \'(DC2Type:uuid)\', message VARCHAR(255) NOT NULL, occurred_at DATETIME NOT NULL, INDEX idx_feed_incident_occurrence_incident (incident_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE feed_incident_occurrence ADD CONSTRAINT fk_feed_incident_occurrence_incident FOREIGN KEY (incident_id) REFERENCES feed_incident (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE feed_incident_occurrence');
    }
}
