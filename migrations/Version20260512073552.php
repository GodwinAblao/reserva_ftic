<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260512073552 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE facility_image DROP FOREIGN KEY `FK_39CDA0059F7E4405`');
        $this->addSql('ALTER TABLE facility_image CHANGE position position INT NOT NULL');
        $this->addSql('DROP INDEX idx_39cda0059f7e4405 ON facility_image');
        $this->addSql('CREATE INDEX IDX_96A23835A7014910 ON facility_image (facility_id)');
        $this->addSql('ALTER TABLE facility_image ADD CONSTRAINT `FK_39CDA0059F7E4405` FOREIGN KEY (facility_id) REFERENCES facility (id) ON DELETE CASCADE');
        $this->addSql('DROP INDEX facility_schedule_block_lookup_idx ON facility_schedule_block');
        $this->addSql('ALTER TABLE facility_schedule_block DROP FOREIGN KEY `FK_77E5F95D9F7E4405`');
        $this->addSql('ALTER TABLE facility_schedule_block ADD schedule_identifier VARCHAR(255) DEFAULT NULL');
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
        $this->addSql('ALTER TABLE facility_image DROP FOREIGN KEY FK_96A23835A7014910');
        $this->addSql('ALTER TABLE facility_image CHANGE position position INT DEFAULT 0 NOT NULL');
        $this->addSql('DROP INDEX idx_96a23835a7014910 ON facility_image');
        $this->addSql('CREATE INDEX IDX_39CDA0059F7E4405 ON facility_image (facility_id)');
        $this->addSql('ALTER TABLE facility_image ADD CONSTRAINT FK_96A23835A7014910 FOREIGN KEY (facility_id) REFERENCES facility (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE facility_schedule_block DROP FOREIGN KEY FK_F7F766FAA7014910');
        $this->addSql('ALTER TABLE facility_schedule_block DROP schedule_identifier');
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
