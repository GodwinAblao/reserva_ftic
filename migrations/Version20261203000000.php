<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Add available_for_reservation to facility table
 */
final class Version20261203000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add available_for_reservation column to facility table';
    }

    public function up(Schema $schema): void
    {
        // Use raw SQL to safely add the column
        $this->addSql(<<<'SQL'
            ALTER TABLE facility ADD IF NOT EXISTS available_for_reservation TINYINT(1) NOT NULL DEFAULT 1
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE facility DROP COLUMN available_for_reservation');
    }
}
