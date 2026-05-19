<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260520120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add class_schedule and class_schedule_notification_log tables; migrate Class Schedule blocks';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE class_schedule (
                id INT AUTO_INCREMENT NOT NULL,
                facility_id INT NOT NULL,
                previous_facility_id INT DEFAULT NULL,
                faculty_user_id INT DEFAULT NULL,
                schedule_date DATE NOT NULL,
                day_of_week VARCHAR(20) DEFAULT NULL,
                start_time TIME NOT NULL,
                end_time TIME NOT NULL,
                course_code VARCHAR(50) NOT NULL,
                section VARCHAR(50) DEFAULT NULL,
                faculty_name VARCHAR(255) DEFAULT NULL,
                faculty_email VARCHAR(180) DEFAULT NULL,
                source VARCHAR(255) DEFAULT NULL,
                import_batch_id VARCHAR(64) DEFAULT NULL,
                schedule_identifier VARCHAR(64) DEFAULT NULL,
                is_relocated TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                INDEX IDX_CLASS_SCHEDULE_FACILITY (facility_id),
                INDEX IDX_CLASS_SCHEDULE_DATE (schedule_date),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
        SQL);

        $this->addSql('ALTER TABLE class_schedule ADD CONSTRAINT FK_CS_FACILITY FOREIGN KEY (facility_id) REFERENCES facility (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE class_schedule ADD CONSTRAINT FK_CS_PREV_FACILITY FOREIGN KEY (previous_facility_id) REFERENCES facility (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE class_schedule ADD CONSTRAINT FK_CS_FACULTY_USER FOREIGN KEY (faculty_user_id) REFERENCES `user` (id) ON DELETE SET NULL');

        $this->addSql(<<<'SQL'
            CREATE TABLE class_schedule_notification_log (
                id INT AUTO_INCREMENT NOT NULL,
                class_schedule_id INT NOT NULL,
                notified_by_id INT NOT NULL,
                faculty_user_id INT DEFAULT NULL,
                previous_facility_id INT DEFAULT NULL,
                new_facility_id INT DEFAULT NULL,
                recipient_email VARCHAR(180) NOT NULL,
                actor_role_label VARCHAR(30) NOT NULL,
                channels VARCHAR(30) NOT NULL,
                message LONGTEXT NOT NULL,
                email_sent TINYINT(1) NOT NULL DEFAULT 0,
                in_app_sent TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                INDEX IDX_CSNL_CREATED (created_at),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
        SQL);

        $this->addSql('ALTER TABLE class_schedule_notification_log ADD CONSTRAINT FK_CSNL_SCHEDULE FOREIGN KEY (class_schedule_id) REFERENCES class_schedule (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE class_schedule_notification_log ADD CONSTRAINT FK_CSNL_NOTIFIED_BY FOREIGN KEY (notified_by_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE class_schedule_notification_log ADD CONSTRAINT FK_CSNL_FACULTY FOREIGN KEY (faculty_user_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE class_schedule_notification_log ADD CONSTRAINT FK_CSNL_PREV_FAC FOREIGN KEY (previous_facility_id) REFERENCES facility (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE class_schedule_notification_log ADD CONSTRAINT FK_CSNL_NEW_FAC FOREIGN KEY (new_facility_id) REFERENCES facility (id) ON DELETE SET NULL');

        $this->addSql(<<<'SQL'
            INSERT INTO class_schedule (
                facility_id, schedule_date, day_of_week, start_time, end_time,
                course_code, section, faculty_name, faculty_email,
                source, schedule_identifier, is_relocated, created_at, updated_at
            )
            SELECT
                b.facility_id,
                b.block_date,
                NULL,
                b.start_time,
                b.end_time,
                CASE
                    WHEN LOCATE(' - ', b.title) > 0 THEN TRIM(SUBSTRING(b.title, 1, LOCATE(' - ', b.title) - 1))
                    ELSE COALESCE(NULLIF(TRIM(b.title), ''), 'CLASS')
                END,
                NULL,
                CASE
                    WHEN LOCATE(' - ', b.title) > 0 THEN TRIM(SUBSTRING(b.title, LOCATE(' - ', b.title) + 3))
                    ELSE NULL
                END,
                NULL,
                b.source,
                b.schedule_identifier,
                0,
                b.created_at,
                b.created_at
            FROM facility_schedule_block b
            WHERE b.type = 'Class Schedule'
        SQL);

        $this->addSql("DELETE FROM facility_schedule_block WHERE type = 'Class Schedule'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE class_schedule_notification_log');
        $this->addSql('DROP TABLE class_schedule');
    }
}
