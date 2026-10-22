<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261022110000 extends AbstractMigration
{
    public function getDescription(): string { return 'Store the configurable account registration limit.'; }
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE registration_settings (id INT NOT NULL, account_limit INT DEFAULT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('INSERT INTO registration_settings (id, account_limit) VALUES (1, NULL)');
    }
    public function down(Schema $schema): void { $this->addSql('DROP TABLE registration_settings'); }
}
