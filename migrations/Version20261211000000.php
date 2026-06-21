<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\Migrations\AbstractMigration;

final class Version20261211000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add admin_approved and admin_approved_at columns to reservation table';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof PostgreSQLPlatform) {
            $this->addSql('ALTER TABLE reservation ADD COLUMN IF NOT EXISTS admin_approved BOOLEAN NOT NULL DEFAULT FALSE');
            $this->addSql('ALTER TABLE reservation ADD COLUMN IF NOT EXISTS admin_approved_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        } else {
            $this->addSql('ALTER TABLE reservation ADD admin_approved TINYINT(1) NOT NULL DEFAULT 0');
            $this->addSql('ALTER TABLE reservation ADD admin_approved_at DATETIME DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof PostgreSQLPlatform) {
            $this->addSql('ALTER TABLE reservation DROP COLUMN IF EXISTS admin_approved');
            $this->addSql('ALTER TABLE reservation DROP COLUMN IF EXISTS admin_approved_at');
        } else {
            $this->addSql('ALTER TABLE reservation DROP admin_approved');
            $this->addSql('ALTER TABLE reservation DROP admin_approved_at');
        }
    }
}
