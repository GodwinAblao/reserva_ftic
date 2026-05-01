<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260501104423 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add student mentor application and OTP verification table';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE mentor_application (id INT AUTO_INCREMENT NOT NULL, email VARCHAR(180) NOT NULL, reason LONGTEXT NOT NULL, specialization VARCHAR(255) NOT NULL, otp_code VARCHAR(10) NOT NULL, otp_expires_at DATETIME NOT NULL, is_otp_verified TINYINT NOT NULL, status VARCHAR(50) NOT NULL, admin_note LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, student_id INT NOT NULL, INDEX IDX_E001ECD7CB944F1A (student_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE mentor_application ADD CONSTRAINT FK_E001ECD7CB944F1A FOREIGN KEY (student_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE mentor_availability CHANGE is_booked is_booked TINYINT NOT NULL');
        $this->addSql('ALTER TABLE mentor_profile CHANGE engagement_points engagement_points INT NOT NULL');
        $this->addSql('ALTER TABLE mentoring_appointment CHANGE status status VARCHAR(50) NOT NULL');
        $this->addSql('ALTER TABLE research_content CHANGE type type VARCHAR(50) NOT NULL, CHANGE category category VARCHAR(100) NOT NULL, CHANGE visibility visibility VARCHAR(30) NOT NULL');
        $this->addSql('ALTER TABLE reservation CHANGE status status VARCHAR(50) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE mentor_application DROP FOREIGN KEY FK_E001ECD7CB944F1A');
        $this->addSql('DROP TABLE mentor_application');
        $this->addSql('ALTER TABLE mentoring_appointment CHANGE status status VARCHAR(50) DEFAULT \'Pending\' NOT NULL');
        $this->addSql('ALTER TABLE mentor_availability CHANGE is_booked is_booked TINYINT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE mentor_profile CHANGE engagement_points engagement_points INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE research_content CHANGE type type VARCHAR(50) DEFAULT \'Article\' NOT NULL, CHANGE category category VARCHAR(100) DEFAULT \'General\' NOT NULL, CHANGE visibility visibility VARCHAR(30) DEFAULT \'Public\' NOT NULL');
        $this->addSql('ALTER TABLE reservation CHANGE status status VARCHAR(50) DEFAULT \'Pending\' NOT NULL');
    }
}
