<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260603060749 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add is_simulated column to reservation table for analytics data filtering';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE reservation ADD is_simulated TINYINT(1) NOT NULL DEFAULT 0
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_reservation_is_simulated ON reservation (is_simulated)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            DROP INDEX idx_reservation_is_simulated ON reservation
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE reservation DROP COLUMN is_simulated
        SQL);
    }
}
