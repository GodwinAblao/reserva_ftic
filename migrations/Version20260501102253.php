<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260501102253 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add mentoring, research, and analytics support tables';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE mentor_availability (id INT AUTO_INCREMENT NOT NULL, available_date DATE NOT NULL, start_time TIME NOT NULL, end_time TIME NOT NULL, is_booked TINYINT NOT NULL, mentor_id INT NOT NULL, INDEX IDX_CB274DDFDB403044 (mentor_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE mentor_profile (id INT AUTO_INCREMENT NOT NULL, display_name VARCHAR(255) NOT NULL, specialization VARCHAR(255) NOT NULL, bio LONGTEXT DEFAULT NULL, engagement_points INT NOT NULL, created_at DATETIME NOT NULL, user_id INT NOT NULL, UNIQUE INDEX UNIQ_185C512AA76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE mentoring_appointment (id INT AUTO_INCREMENT NOT NULL, scheduled_at DATETIME NOT NULL, status VARCHAR(50) NOT NULL, topic LONGTEXT DEFAULT NULL, notes LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, student_id INT NOT NULL, mentor_id INT NOT NULL, availability_id INT DEFAULT NULL, INDEX IDX_8538E640CB944F1A (student_id), INDEX IDX_8538E640DB403044 (mentor_id), INDEX IDX_8538E64061778466 (availability_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE research_content (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(255) NOT NULL, type VARCHAR(50) NOT NULL, category VARCHAR(100) NOT NULL, tags VARCHAR(255) DEFAULT NULL, summary LONGTEXT DEFAULT NULL, body LONGTEXT DEFAULT NULL, file_path VARCHAR(255) DEFAULT NULL, visibility VARCHAR(30) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, author_id INT NOT NULL, INDEX IDX_676B80B3F675F31B (author_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE mentor_availability ADD CONSTRAINT FK_CB274DDFDB403044 FOREIGN KEY (mentor_id) REFERENCES mentor_profile (id)');
        $this->addSql('ALTER TABLE mentor_profile ADD CONSTRAINT FK_185C512AA76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE mentoring_appointment ADD CONSTRAINT FK_8538E640CB944F1A FOREIGN KEY (student_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE mentoring_appointment ADD CONSTRAINT FK_8538E640DB403044 FOREIGN KEY (mentor_id) REFERENCES mentor_profile (id)');
        $this->addSql('ALTER TABLE mentoring_appointment ADD CONSTRAINT FK_8538E64061778466 FOREIGN KEY (availability_id) REFERENCES mentor_availability (id)');
        $this->addSql('ALTER TABLE research_content ADD CONSTRAINT FK_676B80B3F675F31B FOREIGN KEY (author_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE facility CHANGE image image VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE reservation CHANGE status status VARCHAR(50) NOT NULL, CHANGE rejection_reason rejection_reason VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE user CHANGE roles roles JSON NOT NULL, CHANGE identification identification VARCHAR(100) DEFAULT NULL, CHANGE institutional_email institutional_email VARCHAR(180) DEFAULT NULL, CHANGE verification_code verification_code VARCHAR(10) DEFAULT NULL, CHANGE first_name first_name VARCHAR(100) DEFAULT NULL, CHANGE middle_name middle_name VARCHAR(100) DEFAULT NULL, CHANGE last_name last_name VARCHAR(100) DEFAULT NULL, CHANGE degree degree VARCHAR(50) DEFAULT NULL, CHANGE degree_name degree_name VARCHAR(255) DEFAULT NULL, CHANGE profile_picture profile_picture VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE messenger_messages CHANGE delivered_at delivered_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE mentor_availability DROP FOREIGN KEY FK_CB274DDFDB403044');
        $this->addSql('ALTER TABLE mentor_profile DROP FOREIGN KEY FK_185C512AA76ED395');
        $this->addSql('ALTER TABLE mentoring_appointment DROP FOREIGN KEY FK_8538E640CB944F1A');
        $this->addSql('ALTER TABLE mentoring_appointment DROP FOREIGN KEY FK_8538E640DB403044');
        $this->addSql('ALTER TABLE mentoring_appointment DROP FOREIGN KEY FK_8538E64061778466');
        $this->addSql('ALTER TABLE research_content DROP FOREIGN KEY FK_676B80B3F675F31B');
        $this->addSql('DROP TABLE mentor_availability');
        $this->addSql('DROP TABLE mentor_profile');
        $this->addSql('DROP TABLE mentoring_appointment');
        $this->addSql('DROP TABLE research_content');
        $this->addSql('ALTER TABLE facility CHANGE image image VARCHAR(255) DEFAULT \'NULL\'');
        $this->addSql('ALTER TABLE messenger_messages CHANGE delivered_at delivered_at DATETIME DEFAULT \'NULL\'');
        $this->addSql('ALTER TABLE reservation CHANGE status status VARCHAR(50) DEFAULT \'\'\'Pending\'\'\' NOT NULL, CHANGE rejection_reason rejection_reason VARCHAR(255) DEFAULT \'NULL\'');
        $this->addSql('ALTER TABLE `user` CHANGE roles roles LONGTEXT NOT NULL COLLATE `utf8mb4_bin`, CHANGE identification identification VARCHAR(100) DEFAULT \'NULL\', CHANGE institutional_email institutional_email VARCHAR(180) DEFAULT \'NULL\', CHANGE verification_code verification_code VARCHAR(10) DEFAULT \'NULL\', CHANGE first_name first_name VARCHAR(100) DEFAULT \'NULL\', CHANGE middle_name middle_name VARCHAR(100) DEFAULT \'NULL\', CHANGE last_name last_name VARCHAR(100) DEFAULT \'NULL\', CHANGE degree degree VARCHAR(50) DEFAULT \'NULL\', CHANGE degree_name degree_name VARCHAR(255) DEFAULT \'NULL\', CHANGE profile_picture profile_picture VARCHAR(255) DEFAULT \'NULL\'');
    }
}
