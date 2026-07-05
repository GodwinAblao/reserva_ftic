<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\Migrations\AbstractMigration;

final class Version20261215000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create reservation_conflict table for institutional event conflict tracking';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof PostgreSQLPlatform) {
            $this->addSql('CREATE TABLE IF NOT EXISTS reservation_conflict (id SERIAL NOT NULL, reservation_id INT NOT NULL, resolved_by_id INT DEFAULT NULL, conflict_type VARCHAR(50) NOT NULL, conflict_item_id INT NOT NULL, conflict_item_label VARCHAR(500) NOT NULL, conflict_item_facility VARCHAR(255) DEFAULT NULL, conflict_date DATE DEFAULT NULL, conflict_start_time TIME(0) WITHOUT TIME ZONE DEFAULT NULL, conflict_end_time TIME(0) WITHOUT TIME ZONE DEFAULT NULL, conflict_professor VARCHAR(255) DEFAULT NULL, conflict_professor_email VARCHAR(180) DEFAULT NULL, conflict_course VARCHAR(255) DEFAULT NULL, conflict_section VARCHAR(100) DEFAULT NULL, conflict_status VARCHAR(100) DEFAULT NULL, conflict_owner VARCHAR(100) DEFAULT NULL, conflict_owner_email VARCHAR(180) DEFAULT NULL, resolution VARCHAR(100) DEFAULT NULL, resolution_notes TEXT DEFAULT NULL, resolved_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
            $this->addSql('CREATE INDEX IDX_RC_RESERVATION ON reservation_conflict (reservation_id)');
            $this->addSql('CREATE INDEX IDX_RC_RESOLVED_BY ON reservation_conflict (resolved_by_id)');
            $this->addSql('ALTER TABLE reservation_conflict ADD CONSTRAINT FK_RC_RESERVATION FOREIGN KEY (reservation_id) REFERENCES reservation (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
            $this->addSql('ALTER TABLE reservation_conflict ADD CONSTRAINT FK_RC_RESOLVED_BY FOREIGN KEY (resolved_by_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        } else {
            $this->addSql('CREATE TABLE reservation_conflict (id INT AUTO_INCREMENT NOT NULL, reservation_id INT NOT NULL, resolved_by_id INT DEFAULT NULL, conflict_type VARCHAR(50) NOT NULL, conflict_item_id INT NOT NULL, conflict_item_label VARCHAR(500) NOT NULL, conflict_item_facility VARCHAR(255) DEFAULT NULL, conflict_date DATE DEFAULT NULL, conflict_start_time TIME DEFAULT NULL, conflict_end_time TIME DEFAULT NULL, conflict_professor VARCHAR(255) DEFAULT NULL, conflict_professor_email VARCHAR(180) DEFAULT NULL, conflict_course VARCHAR(255) DEFAULT NULL, conflict_section VARCHAR(100) DEFAULT NULL, conflict_status VARCHAR(100) DEFAULT NULL, conflict_owner VARCHAR(100) DEFAULT NULL, conflict_owner_email VARCHAR(180) DEFAULT NULL, resolution VARCHAR(100) DEFAULT NULL, resolution_notes LONGTEXT DEFAULT NULL, resolved_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');
            $this->addSql('ALTER TABLE reservation_conflict ADD CONSTRAINT FK_RC_RESERVATION FOREIGN KEY (reservation_id) REFERENCES reservation (id) ON DELETE CASCADE');
            $this->addSql('ALTER TABLE reservation_conflict ADD CONSTRAINT FK_RC_RESOLVED_BY FOREIGN KEY (resolved_by_id) REFERENCES user (id) ON DELETE SET NULL');
        }
    }

    public function down(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        if ($platform instanceof PostgreSQLPlatform) {
            $this->addSql('ALTER TABLE reservation_conflict DROP CONSTRAINT IF EXISTS FK_RC_RESERVATION');
            $this->addSql('ALTER TABLE reservation_conflict DROP CONSTRAINT IF EXISTS FK_RC_RESOLVED_BY');
            $this->addSql('DROP TABLE IF EXISTS reservation_conflict');
        } else {
            $this->addSql('ALTER TABLE reservation_conflict DROP FOREIGN KEY FK_RC_RESERVATION');
            $this->addSql('ALTER TABLE reservation_conflict DROP FOREIGN KEY FK_RC_RESOLVED_BY');
            $this->addSql('DROP TABLE reservation_conflict');
        }
    }
}