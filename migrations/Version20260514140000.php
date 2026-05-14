<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Add availability_time to mentor_application
 */
final class Version20260514140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add availability_time column to mentor_application table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mentor_application ADD availability_time VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mentor_application DROP COLUMN availability_time');
    }
}