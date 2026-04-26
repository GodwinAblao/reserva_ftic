<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260221184810 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE facility (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, capacity INT NOT NULL, description LONGTEXT DEFAULT NULL, image VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE messenger_messages (id BIGINT AUTO_INCREMENT NOT NULL, body LONGTEXT NOT NULL, headers LONGTEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL, INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 (queue_name, available_at, delivered_at, id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE user CHANGE roles roles JSON NOT NULL, CHANGE identification identification VARCHAR(100) DEFAULT NULL, CHANGE institutional_email institutional_email VARCHAR(180) DEFAULT NULL, CHANGE first_name first_name VARCHAR(100) DEFAULT NULL, CHANGE middle_name middle_name VARCHAR(100) DEFAULT NULL, CHANGE last_name last_name VARCHAR(100) DEFAULT NULL, CHANGE degree degree VARCHAR(50) DEFAULT NULL, CHANGE degree_name degree_name VARCHAR(255) DEFAULT NULL, CHANGE profile_picture profile_picture VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE facility');
        $this->addSql('DROP TABLE messenger_messages');
        $this->addSql('ALTER TABLE `user` CHANGE roles roles LONGTEXT NOT NULL COLLATE `utf8mb4_bin`, CHANGE identification identification VARCHAR(100) DEFAULT \'NULL\', CHANGE institutional_email institutional_email VARCHAR(180) DEFAULT \'NULL\', CHANGE first_name first_name VARCHAR(100) DEFAULT \'NULL\', CHANGE middle_name middle_name VARCHAR(100) DEFAULT \'NULL\', CHANGE last_name last_name VARCHAR(100) DEFAULT \'NULL\', CHANGE degree degree VARCHAR(50) DEFAULT \'NULL\', CHANGE degree_name degree_name VARCHAR(255) DEFAULT \'NULL\', CHANGE profile_picture profile_picture VARCHAR(255) DEFAULT \'NULL\'');
    }
}
