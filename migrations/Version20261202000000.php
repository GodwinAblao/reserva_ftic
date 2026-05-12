<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Add schedule_identifier to facility_schedule_block
 */
final class Version20261202000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add schedule_identifier column to facility_schedule_block table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE facility_schedule_block ADD schedule_identifier VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE facility_schedule_block DROP COLUMN schedule_identifier');
    }
}
