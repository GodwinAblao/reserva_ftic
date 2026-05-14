<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add available_for_reservation flag to facility table
 */
final class Version20260515100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add available_for_reservation column to facility table to control facility availability for reservations';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE facility ADD available_for_reservation TINYINT(1) NOT NULL DEFAULT 1');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE facility DROP COLUMN available_for_reservation');
    }
}
