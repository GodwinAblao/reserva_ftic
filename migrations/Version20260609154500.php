<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration to add cancellation_reason column to reservation table
 */
final class Version20260609154500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add cancellation_reason column to reservation table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reservation ADD cancellation_reason TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reservation DROP cancellation_reason');
    }
}
