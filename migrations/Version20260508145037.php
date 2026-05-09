<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260508145037 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE mentor_custom_request (id INT AUTO_INCREMENT NOT NULL, message LONGTEXT NOT NULL, status VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL, mentor_response LONGTEXT DEFAULT NULL, student_id INT NOT NULL, mentor_profile_id INT NOT NULL, INDEX IDX_6986868CCB944F1A (student_id), INDEX IDX_6986868C92E677D4 (mentor_profile_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE notifications (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(50) NOT NULL, title VARCHAR(255) NOT NULL, message LONGTEXT NOT NULL, status VARCHAR(20) NOT NULL, is_read TINYINT NOT NULL, created_at DATETIME NOT NULL, reference_id INT DEFAULT NULL, user_id INT NOT NULL, INDEX IDX_6000B0D3A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE mentor_custom_request ADD CONSTRAINT FK_6986868CCB944F1A FOREIGN KEY (student_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE mentor_custom_request ADD CONSTRAINT FK_6986868C92E677D4 FOREIGN KEY (mentor_profile_id) REFERENCES mentor_profile (id)');
        $this->addSql('ALTER TABLE notifications ADD CONSTRAINT FK_6000B0D3A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE mentor_application ADD first_name VARCHAR(100) DEFAULT NULL, ADD middle_name VARCHAR(100) DEFAULT NULL, ADD last_name VARCHAR(100) DEFAULT NULL, ADD contact_number VARCHAR(20) DEFAULT NULL, ADD years_of_experience INT DEFAULT NULL, ADD current_profession VARCHAR(150) DEFAULT NULL, ADD highest_education VARCHAR(150) DEFAULT NULL, ADD supporting_description LONGTEXT DEFAULT NULL, ADD proof_of_expertise JSON DEFAULT NULL, ADD valid_until DATETIME DEFAULT NULL, DROP otp_code, DROP otp_expires_at, DROP is_otp_verified, CHANGE email email VARCHAR(255) NOT NULL, CHANGE reason reason LONGTEXT DEFAULT NULL, CHANGE status status VARCHAR(50) NOT NULL');
        $this->addSql('ALTER TABLE mentor_availability CHANGE is_booked is_booked TINYINT NOT NULL');
        $this->addSql('ALTER TABLE mentor_profile CHANGE engagement_points engagement_points INT NOT NULL');
        $this->addSql('ALTER TABLE mentoring_appointment CHANGE status status VARCHAR(50) NOT NULL');
        $this->addSql('ALTER TABLE research_content CHANGE type type VARCHAR(50) NOT NULL, CHANGE category category VARCHAR(100) NOT NULL, CHANGE visibility visibility VARCHAR(30) NOT NULL');
        $this->addSql('ALTER TABLE reservation CHANGE status status VARCHAR(50) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE mentor_custom_request DROP FOREIGN KEY FK_6986868CCB944F1A');
        $this->addSql('ALTER TABLE mentor_custom_request DROP FOREIGN KEY FK_6986868C92E677D4');
        $this->addSql('ALTER TABLE notifications DROP FOREIGN KEY FK_6000B0D3A76ED395');
        $this->addSql('DROP TABLE mentor_custom_request');
        $this->addSql('DROP TABLE notifications');
        $this->addSql('ALTER TABLE mentoring_appointment CHANGE status status VARCHAR(50) DEFAULT \'Pending\' NOT NULL');
        $this->addSql('ALTER TABLE mentor_application ADD otp_code VARCHAR(10) NOT NULL, ADD otp_expires_at DATETIME NOT NULL, ADD is_otp_verified TINYINT DEFAULT 0 NOT NULL, DROP first_name, DROP middle_name, DROP last_name, DROP contact_number, DROP years_of_experience, DROP current_profession, DROP highest_education, DROP supporting_description, DROP proof_of_expertise, DROP valid_until, CHANGE email email VARCHAR(180) NOT NULL, CHANGE reason reason LONGTEXT NOT NULL, CHANGE status status VARCHAR(50) DEFAULT \'Awaiting OTP\' NOT NULL');
        $this->addSql('ALTER TABLE mentor_availability CHANGE is_booked is_booked TINYINT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE mentor_profile CHANGE engagement_points engagement_points INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE research_content CHANGE type type VARCHAR(50) DEFAULT \'Article\' NOT NULL, CHANGE category category VARCHAR(100) DEFAULT \'General\' NOT NULL, CHANGE visibility visibility VARCHAR(30) DEFAULT \'Public\' NOT NULL');
        $this->addSql('ALTER TABLE reservation CHANGE status status VARCHAR(50) DEFAULT \'Pending\' NOT NULL');
    }
}
