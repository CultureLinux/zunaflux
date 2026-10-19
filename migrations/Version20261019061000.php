<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema; use Doctrine\Migrations\AbstractMigration;
final class Version20261019061000 extends AbstractMigration { public function getDescription(): string{return 'Adds feed collection health metadata.';} public function up(Schema $schema):void{$this->addSql('ALTER TABLE feed ADD last_success_at DATETIME DEFAULT NULL, ADD failure_count INT NOT NULL DEFAULT 0, ADD last_error VARCHAR(255) DEFAULT NULL');} public function down(Schema $schema):void{$this->addSql('ALTER TABLE feed DROP last_success_at, DROP failure_count, DROP last_error');} }
