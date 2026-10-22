<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261022120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store the language preference for each user.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE user_preference ADD locale VARCHAR(2) DEFAULT 'fr' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_preference DROP locale');
    }
}
