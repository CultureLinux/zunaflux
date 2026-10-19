<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261019000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Creates the initial Zunaflux account table for MariaDB 10.11.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE app_user (id BINARY(16) NOT NULL COMMENT \'(DC2Type:uuid)\', email_normalized VARCHAR(254) NOT NULL, password_hash LONGTEXT NOT NULL, roles JSON NOT NULL, status VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', disabled_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX uniq_app_user_email_normalized (email_normalized), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE app_user');
    }
}
