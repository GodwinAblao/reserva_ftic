<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260520105052 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE reservation_status_log (id INT AUTO_INCREMENT NOT NULL, previous_status VARCHAR(50) NOT NULL, new_status VARCHAR(50) NOT NULL, actor_role_label VARCHAR(30) NOT NULL, action VARCHAR(30) NOT NULL, note LONGTEXT DEFAULT NULL, changed_at DATETIME NOT NULL, reservation_id INT NOT NULL, changed_by_id INT NOT NULL, INDEX IDX_31FAF1D3B83297E7 (reservation_id), INDEX IDX_31FAF1D3828AD0A0 (changed_by_id), INDEX idx_reservation_status_log_changed_at (changed_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE reservation_status_log ADD CONSTRAINT FK_31FAF1D3B83297E7 FOREIGN KEY (reservation_id) REFERENCES reservation (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE reservation_status_log ADD CONSTRAINT FK_31FAF1D3828AD0A0 FOREIGN KEY (changed_by_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('DROP INDEX IDX_CLASS_SCHEDULE_DATE ON class_schedule');
        $this->addSql('ALTER TABLE class_schedule DROP FOREIGN KEY `FK_CS_FACILITY`');
        $this->addSql('ALTER TABLE class_schedule DROP FOREIGN KEY `FK_CS_FACULTY_USER`');
        $this->addSql('ALTER TABLE class_schedule DROP FOREIGN KEY `FK_CS_PREV_FACILITY`');
        $this->addSql('DROP INDEX idx_class_schedule_facility ON class_schedule');
        $this->addSql('CREATE INDEX IDX_EEF6DAABA7014910 ON class_schedule (facility_id)');
        $this->addSql('DROP INDEX fk_cs_prev_facility ON class_schedule');
        $this->addSql('CREATE INDEX IDX_EEF6DAAB15CB427A ON class_schedule (previous_facility_id)');
        $this->addSql('DROP INDEX fk_cs_faculty_user ON class_schedule');
        $this->addSql('CREATE INDEX IDX_EEF6DAABE67D05CF ON class_schedule (faculty_user_id)');
        $this->addSql('ALTER TABLE class_schedule ADD CONSTRAINT `FK_CS_FACILITY` FOREIGN KEY (facility_id) REFERENCES facility (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE class_schedule ADD CONSTRAINT `FK_CS_FACULTY_USER` FOREIGN KEY (faculty_user_id) REFERENCES user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE class_schedule ADD CONSTRAINT `FK_CS_PREV_FACILITY` FOREIGN KEY (previous_facility_id) REFERENCES facility (id) ON DELETE SET NULL');
        $this->addSql('DROP INDEX IDX_CSNL_CREATED ON class_schedule_notification_log');
        $this->addSql('ALTER TABLE class_schedule_notification_log DROP FOREIGN KEY `FK_CSNL_FACULTY`');
        $this->addSql('ALTER TABLE class_schedule_notification_log DROP FOREIGN KEY `FK_CSNL_NEW_FAC`');
        $this->addSql('ALTER TABLE class_schedule_notification_log DROP FOREIGN KEY `FK_CSNL_NOTIFIED_BY`');
        $this->addSql('ALTER TABLE class_schedule_notification_log DROP FOREIGN KEY `FK_CSNL_PREV_FAC`');
        $this->addSql('ALTER TABLE class_schedule_notification_log DROP FOREIGN KEY `FK_CSNL_SCHEDULE`');
        $this->addSql('DROP INDEX fk_csnl_schedule ON class_schedule_notification_log');
        $this->addSql('CREATE INDEX IDX_48B6915C9C650DE3 ON class_schedule_notification_log (class_schedule_id)');
        $this->addSql('DROP INDEX fk_csnl_notified_by ON class_schedule_notification_log');
        $this->addSql('CREATE INDEX IDX_48B6915CE97D56E9 ON class_schedule_notification_log (notified_by_id)');
        $this->addSql('DROP INDEX fk_csnl_faculty ON class_schedule_notification_log');
        $this->addSql('CREATE INDEX IDX_48B6915CE67D05CF ON class_schedule_notification_log (faculty_user_id)');
        $this->addSql('DROP INDEX fk_csnl_prev_fac ON class_schedule_notification_log');
        $this->addSql('CREATE INDEX IDX_48B6915C15CB427A ON class_schedule_notification_log (previous_facility_id)');
        $this->addSql('DROP INDEX fk_csnl_new_fac ON class_schedule_notification_log');
        $this->addSql('CREATE INDEX IDX_48B6915C350EF868 ON class_schedule_notification_log (new_facility_id)');
        $this->addSql('ALTER TABLE class_schedule_notification_log ADD CONSTRAINT `FK_CSNL_FACULTY` FOREIGN KEY (faculty_user_id) REFERENCES user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE class_schedule_notification_log ADD CONSTRAINT `FK_CSNL_NEW_FAC` FOREIGN KEY (new_facility_id) REFERENCES facility (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE class_schedule_notification_log ADD CONSTRAINT `FK_CSNL_NOTIFIED_BY` FOREIGN KEY (notified_by_id) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE class_schedule_notification_log ADD CONSTRAINT `FK_CSNL_PREV_FAC` FOREIGN KEY (previous_facility_id) REFERENCES facility (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE class_schedule_notification_log ADD CONSTRAINT `FK_CSNL_SCHEDULE` FOREIGN KEY (class_schedule_id) REFERENCES class_schedule (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE facility DROP FOREIGN KEY `fk_facility_parent`');
        $this->addSql('ALTER TABLE facility CHANGE available_for_reservation available_for_reservation TINYINT NOT NULL');
        $this->addSql('DROP INDEX idx_9dfb30d727aca70 ON facility');
        $this->addSql('CREATE INDEX IDX_105994B2727ACA70 ON facility (parent_id)');
        $this->addSql('ALTER TABLE facility ADD CONSTRAINT `fk_facility_parent` FOREIGN KEY (parent_id) REFERENCES facility (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE facility_image DROP FOREIGN KEY `FK_39CDA0059F7E4405`');
        $this->addSql('ALTER TABLE facility_image CHANGE position position INT NOT NULL');
        $this->addSql('DROP INDEX idx_39cda0059f7e4405 ON facility_image');
        $this->addSql('CREATE INDEX IDX_96A23835A7014910 ON facility_image (facility_id)');
        $this->addSql('ALTER TABLE facility_image ADD CONSTRAINT `FK_39CDA0059F7E4405` FOREIGN KEY (facility_id) REFERENCES facility (id) ON DELETE CASCADE');
        $this->addSql('DROP INDEX facility_schedule_block_lookup_idx ON facility_schedule_block');
        $this->addSql('ALTER TABLE facility_schedule_block DROP FOREIGN KEY `FK_77E5F95D9F7E4405`');
        $this->addSql('DROP INDEX idx_77e5f95d9f7e4405 ON facility_schedule_block');
        $this->addSql('CREATE INDEX IDX_F7F766FAA7014910 ON facility_schedule_block (facility_id)');
        $this->addSql('ALTER TABLE facility_schedule_block ADD CONSTRAINT `FK_77E5F95D9F7E4405` FOREIGN KEY (facility_id) REFERENCES facility (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE mentor_application CHANGE status status VARCHAR(50) NOT NULL');
        $this->addSql('ALTER TABLE mentor_availability CHANGE is_booked is_booked TINYINT NOT NULL');
        $this->addSql('ALTER TABLE mentor_custom_request DROP FOREIGN KEY `FK_3A5F8C4C20F29E8B`');
        $this->addSql('ALTER TABLE mentor_custom_request DROP FOREIGN KEY `FK_3A5F8C4FCB944F1A`');
        $this->addSql('DROP INDEX idx_3a5f8c4fcb944f1a ON mentor_custom_request');
        $this->addSql('CREATE INDEX IDX_6986868CCB944F1A ON mentor_custom_request (student_id)');
        $this->addSql('DROP INDEX idx_3a5f8c4c20f29e8b ON mentor_custom_request');
        $this->addSql('CREATE INDEX IDX_6986868C92E677D4 ON mentor_custom_request (mentor_profile_id)');
        $this->addSql('ALTER TABLE mentor_custom_request ADD CONSTRAINT `FK_3A5F8C4C20F29E8B` FOREIGN KEY (mentor_profile_id) REFERENCES mentor_profile (id)');
        $this->addSql('ALTER TABLE mentor_custom_request ADD CONSTRAINT `FK_3A5F8C4FCB944F1A` FOREIGN KEY (student_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE mentor_profile CHANGE engagement_points engagement_points INT NOT NULL');
        $this->addSql('ALTER TABLE mentoring_appointment CHANGE status status VARCHAR(50) NOT NULL');
        $this->addSql('ALTER TABLE notifications DROP FOREIGN KEY `FK_16C413C5A76ED395`');
        $this->addSql('ALTER TABLE notifications CHANGE type type VARCHAR(50) NOT NULL, CHANGE status status VARCHAR(20) NOT NULL, CHANGE is_read is_read TINYINT NOT NULL');
        $this->addSql('DROP INDEX idx_16c413c5a76ed395 ON notifications');
        $this->addSql('CREATE INDEX IDX_6000B0D3A76ED395 ON notifications (user_id)');
        $this->addSql('ALTER TABLE notifications ADD CONSTRAINT `FK_16C413C5A76ED395` FOREIGN KEY (user_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE research_content CHANGE type type VARCHAR(50) NOT NULL, CHANGE category category VARCHAR(100) NOT NULL, CHANGE visibility visibility VARCHAR(30) NOT NULL');
        $this->addSql('ALTER TABLE reservation CHANGE status status VARCHAR(50) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE reservation_status_log DROP FOREIGN KEY FK_31FAF1D3B83297E7');
        $this->addSql('ALTER TABLE reservation_status_log DROP FOREIGN KEY FK_31FAF1D3828AD0A0');
        $this->addSql('DROP TABLE reservation_status_log');
        $this->addSql('ALTER TABLE class_schedule DROP FOREIGN KEY FK_EEF6DAABA7014910');
        $this->addSql('ALTER TABLE class_schedule DROP FOREIGN KEY FK_EEF6DAAB15CB427A');
        $this->addSql('ALTER TABLE class_schedule DROP FOREIGN KEY FK_EEF6DAABE67D05CF');
        $this->addSql('CREATE INDEX IDX_CLASS_SCHEDULE_DATE ON class_schedule (schedule_date)');
        $this->addSql('DROP INDEX idx_eef6daabe67d05cf ON class_schedule');
        $this->addSql('CREATE INDEX FK_CS_FACULTY_USER ON class_schedule (faculty_user_id)');
        $this->addSql('DROP INDEX idx_eef6daaba7014910 ON class_schedule');
        $this->addSql('CREATE INDEX IDX_CLASS_SCHEDULE_FACILITY ON class_schedule (facility_id)');
        $this->addSql('DROP INDEX idx_eef6daab15cb427a ON class_schedule');
        $this->addSql('CREATE INDEX FK_CS_PREV_FACILITY ON class_schedule (previous_facility_id)');
        $this->addSql('ALTER TABLE class_schedule ADD CONSTRAINT FK_EEF6DAABA7014910 FOREIGN KEY (facility_id) REFERENCES facility (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE class_schedule ADD CONSTRAINT FK_EEF6DAAB15CB427A FOREIGN KEY (previous_facility_id) REFERENCES facility (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE class_schedule ADD CONSTRAINT FK_EEF6DAABE67D05CF FOREIGN KEY (faculty_user_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE class_schedule_notification_log DROP FOREIGN KEY FK_48B6915C9C650DE3');
        $this->addSql('ALTER TABLE class_schedule_notification_log DROP FOREIGN KEY FK_48B6915CE97D56E9');
        $this->addSql('ALTER TABLE class_schedule_notification_log DROP FOREIGN KEY FK_48B6915CE67D05CF');
        $this->addSql('ALTER TABLE class_schedule_notification_log DROP FOREIGN KEY FK_48B6915C15CB427A');
        $this->addSql('ALTER TABLE class_schedule_notification_log DROP FOREIGN KEY FK_48B6915C350EF868');
        $this->addSql('CREATE INDEX IDX_CSNL_CREATED ON class_schedule_notification_log (created_at)');
        $this->addSql('DROP INDEX idx_48b6915c15cb427a ON class_schedule_notification_log');
        $this->addSql('CREATE INDEX FK_CSNL_PREV_FAC ON class_schedule_notification_log (previous_facility_id)');
        $this->addSql('DROP INDEX idx_48b6915c9c650de3 ON class_schedule_notification_log');
        $this->addSql('CREATE INDEX FK_CSNL_SCHEDULE ON class_schedule_notification_log (class_schedule_id)');
        $this->addSql('DROP INDEX idx_48b6915c350ef868 ON class_schedule_notification_log');
        $this->addSql('CREATE INDEX FK_CSNL_NEW_FAC ON class_schedule_notification_log (new_facility_id)');
        $this->addSql('DROP INDEX idx_48b6915ce97d56e9 ON class_schedule_notification_log');
        $this->addSql('CREATE INDEX FK_CSNL_NOTIFIED_BY ON class_schedule_notification_log (notified_by_id)');
        $this->addSql('DROP INDEX idx_48b6915ce67d05cf ON class_schedule_notification_log');
        $this->addSql('CREATE INDEX FK_CSNL_FACULTY ON class_schedule_notification_log (faculty_user_id)');
        $this->addSql('ALTER TABLE class_schedule_notification_log ADD CONSTRAINT FK_48B6915C9C650DE3 FOREIGN KEY (class_schedule_id) REFERENCES class_schedule (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE class_schedule_notification_log ADD CONSTRAINT FK_48B6915CE97D56E9 FOREIGN KEY (notified_by_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE class_schedule_notification_log ADD CONSTRAINT FK_48B6915CE67D05CF FOREIGN KEY (faculty_user_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE class_schedule_notification_log ADD CONSTRAINT FK_48B6915C15CB427A FOREIGN KEY (previous_facility_id) REFERENCES facility (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE class_schedule_notification_log ADD CONSTRAINT FK_48B6915C350EF868 FOREIGN KEY (new_facility_id) REFERENCES facility (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE facility DROP FOREIGN KEY FK_105994B2727ACA70');
        $this->addSql('ALTER TABLE facility CHANGE available_for_reservation available_for_reservation TINYINT DEFAULT 1 NOT NULL');
        $this->addSql('DROP INDEX idx_105994b2727aca70 ON facility');
        $this->addSql('CREATE INDEX IDX_9DFB30D727ACA70 ON facility (parent_id)');
        $this->addSql('ALTER TABLE facility ADD CONSTRAINT FK_105994B2727ACA70 FOREIGN KEY (parent_id) REFERENCES facility (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE facility_image DROP FOREIGN KEY FK_96A23835A7014910');
        $this->addSql('ALTER TABLE facility_image CHANGE position position INT DEFAULT 0 NOT NULL');
        $this->addSql('DROP INDEX idx_96a23835a7014910 ON facility_image');
        $this->addSql('CREATE INDEX IDX_39CDA0059F7E4405 ON facility_image (facility_id)');
        $this->addSql('ALTER TABLE facility_image ADD CONSTRAINT FK_96A23835A7014910 FOREIGN KEY (facility_id) REFERENCES facility (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE facility_schedule_block DROP FOREIGN KEY FK_F7F766FAA7014910');
        $this->addSql('CREATE INDEX facility_schedule_block_lookup_idx ON facility_schedule_block (facility_id, block_date, start_time, end_time)');
        $this->addSql('DROP INDEX idx_f7f766faa7014910 ON facility_schedule_block');
        $this->addSql('CREATE INDEX IDX_77E5F95D9F7E4405 ON facility_schedule_block (facility_id)');
        $this->addSql('ALTER TABLE facility_schedule_block ADD CONSTRAINT FK_F7F766FAA7014910 FOREIGN KEY (facility_id) REFERENCES facility (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE mentoring_appointment CHANGE status status VARCHAR(50) DEFAULT \'Pending\' NOT NULL');
        $this->addSql('ALTER TABLE mentor_application CHANGE status status VARCHAR(50) DEFAULT \'Pending\' NOT NULL');
        $this->addSql('ALTER TABLE mentor_availability CHANGE is_booked is_booked TINYINT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE mentor_custom_request DROP FOREIGN KEY FK_6986868CCB944F1A');
        $this->addSql('ALTER TABLE mentor_custom_request DROP FOREIGN KEY FK_6986868C92E677D4');
        $this->addSql('DROP INDEX idx_6986868ccb944f1a ON mentor_custom_request');
        $this->addSql('CREATE INDEX IDX_3A5F8C4FCB944F1A ON mentor_custom_request (student_id)');
        $this->addSql('DROP INDEX idx_6986868c92e677d4 ON mentor_custom_request');
        $this->addSql('CREATE INDEX IDX_3A5F8C4C20F29E8B ON mentor_custom_request (mentor_profile_id)');
        $this->addSql('ALTER TABLE mentor_custom_request ADD CONSTRAINT FK_6986868CCB944F1A FOREIGN KEY (student_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE mentor_custom_request ADD CONSTRAINT FK_6986868C92E677D4 FOREIGN KEY (mentor_profile_id) REFERENCES mentor_profile (id)');
        $this->addSql('ALTER TABLE mentor_profile CHANGE engagement_points engagement_points INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE notifications DROP FOREIGN KEY FK_6000B0D3A76ED395');
        $this->addSql('ALTER TABLE notifications CHANGE type type VARCHAR(50) DEFAULT \'general\' NOT NULL, CHANGE status status VARCHAR(20) DEFAULT \'Pending\' NOT NULL, CHANGE is_read is_read TINYINT DEFAULT 0 NOT NULL');
        $this->addSql('DROP INDEX idx_6000b0d3a76ed395 ON notifications');
        $this->addSql('CREATE INDEX IDX_16C413C5A76ED395 ON notifications (user_id)');
        $this->addSql('ALTER TABLE notifications ADD CONSTRAINT FK_6000B0D3A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE research_content CHANGE type type VARCHAR(50) DEFAULT \'Article\' NOT NULL, CHANGE category category VARCHAR(100) DEFAULT \'General\' NOT NULL, CHANGE visibility visibility VARCHAR(30) DEFAULT \'Public\' NOT NULL');
        $this->addSql('ALTER TABLE reservation CHANGE status status VARCHAR(50) DEFAULT \'Pending\' NOT NULL');
    }
}
