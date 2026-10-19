<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261019030000 extends AbstractMigration
{
    public function getDescription(): string { return 'Persists HTTP validators used for conditional feed collection.'; }
    public function up(Schema $schema): void { $this->addSql('ALTER TABLE feed ADD etag VARCHAR(255) DEFAULT NULL, ADD last_modified VARCHAR(255) DEFAULT NULL'); }
    public function down(Schema $schema): void { $this->addSql('ALTER TABLE feed DROP etag, DROP last_modified'); }
}
