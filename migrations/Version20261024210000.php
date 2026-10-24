<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261024210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Stores the Messenger worker heartbeat for administration diagnostics.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE messenger_worker_heartbeat (name VARCHAR(64) NOT NULL, last_seen_at DATETIME NOT NULL, PRIMARY KEY(name)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE messenger_worker_heartbeat');
    }
}
