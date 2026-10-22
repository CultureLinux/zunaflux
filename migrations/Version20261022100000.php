<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261022100000 extends AbstractMigration
{
    public function getDescription(): string { return 'Count accepted Web Push notifications by day.'; }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE notification_daily_count (day DATE NOT NULL, total INT NOT NULL, PRIMARY KEY(day)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void { $this->addSql('DROP TABLE notification_daily_count'); }
}
