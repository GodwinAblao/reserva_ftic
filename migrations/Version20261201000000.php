<?php

declare(strict_types=1);

namespace Application\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261201000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add notifications table and update mentor_application with new fields';
    }

    public function up(Schema $schema): void
    {
        // Create notifications table
        $this->addSql('CREATE TABLE notifications (
            id INT AUTO_INCREMENT NOT NULL,
            user_id INT NOT NULL,
            type VARCHAR(50) NOT NULL DEFAULT "general",
            title VARCHAR(255) NOT NULL,
            message LONGTEXT NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT "Pending",
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            reference_id INT DEFAULT NULL,
            INDEX IDX_16C413C5A76ED395 (user_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE notifications ADD CONSTRAINT FK_16C413C5A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id)');

        // Update mentor_application table - add new fields
        $this->addSql('ALTER TABLE mentor_application 
            ADD COLUMN first_name VARCHAR(100) DEFAULT NULL,
            ADD COLUMN middle_name VARCHAR(100) DEFAULT NULL,
            ADD COLUMN last_name VARCHAR(100) DEFAULT NULL,
            ADD COLUMN contact_number VARCHAR(20) DEFAULT NULL,
            ADD COLUMN years_of_experience INT DEFAULT NULL,
            ADD COLUMN current_profession VARCHAR(150) DEFAULT NULL,
            ADD COLUMN highest_education VARCHAR(150) DEFAULT NULL,
            ADD COLUMN supporting_description LONGTEXT DEFAULT NULL,
            ADD COLUMN proof_of_expertise JSON DEFAULT NULL,
            ADD COLUMN valid_until DATETIME DEFAULT NULL,
            MODIFY status VARCHAR(20) NOT NULL DEFAULT "Pending",
            MODIFY reason LONGTEXT DEFAULT NULL');

        // Drop OTP-related columns (optional - comment out if you want to keep them temporarily)
        // $this->addSql('ALTER TABLE mentor_application DROP COLUMN otp_code');
        // $this->addSql('ALTER TABLE mentor_application DROP COLUMN otp_expires_at');
        // $this->addSql('ALTER TABLE mentor_application DROP COLUMN is_otp_verified');
    }

    public function down(Schema $schema): void
    {
        // Drop notifications table
        $this->addSql('ALTER TABLE notifications DROP FOREIGN KEY FK_16C413C5A76ED395');
        $this->addSql('DROP TABLE notifications');

        // Revert mentor_application changes
        $this->addSql('ALTER TABLE mentor_application 
            DROP COLUMN first_name,
            DROP COLUMN middle_name,
            DROP COLUMN last_name,
            DROP COLUMN contact_number,
            DROP COLUMN years_of_experience,
            DROP COLUMN current_profession,
            DROP COLUMN highest_education,
            DROP COLUMN supporting_description,
            DROP COLUMN proof_of_expertise,
            DROP COLUMN valid_until');
    }
}
