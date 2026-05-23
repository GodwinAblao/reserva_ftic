<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260524000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create mentoring_audit_log table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE IF NOT EXISTS mentoring_audit_log (
            id INT AUTO_INCREMENT NOT NULL,
            performed_by_id INT DEFAULT NULL,
            subject_type VARCHAR(30) NOT NULL,
            subject_id INT DEFAULT NULL,
            subject_label VARCHAR(120) NOT NULL,
            action VARCHAR(40) NOT NULL,
            previous_status VARCHAR(50) DEFAULT NULL,
            new_status VARCHAR(50) DEFAULT NULL,
            performed_by_name VARCHAR(60) DEFAULT NULL,
            performed_by_role VARCHAR(30) DEFAULT NULL,
            note LONGTEXT DEFAULT NULL,
            logged_at DATETIME NOT NULL,
            INDEX idx_mentoring_audit_log_logged_at (logged_at),
            INDEX IDX_mentoring_audit_performed_by (performed_by_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE mentoring_audit_log ADD CONSTRAINT FK_mentoring_audit_performed_by FOREIGN KEY (performed_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mentoring_audit_log DROP FOREIGN KEY FK_mentoring_audit_performed_by');
        $this->addSql('DROP TABLE IF EXISTS mentoring_audit_log');
    }
}
