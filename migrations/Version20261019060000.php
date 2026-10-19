<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema; use Doctrine\Migrations\AbstractMigration;
final class Version20261019060000 extends AbstractMigration { public function getDescription(): string{return 'Adds per-account subscription quota for administration.';} public function up(Schema $schema):void{$this->addSql('ALTER TABLE app_user ADD subscription_limit INT DEFAULT NULL');} public function down(Schema $schema):void{$this->addSql('ALTER TABLE app_user DROP subscription_limit');} }
