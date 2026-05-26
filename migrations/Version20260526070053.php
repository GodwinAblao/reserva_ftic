<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260526070053 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create specialization table for managing specializations';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE specialization (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        
        // Insert default specializations
        $this->addSql("INSERT INTO specialization (name, created_at) VALUES 
            ('Business Analytics', NOW()),
            ('BSITBA', NOW()),
            ('BSCS', NOW()),
            ('Software Engineering', NOW()),
            ('Data Science', NOW()),
            ('Cybersecurity', NOW()),
            ('Cloud Computing', NOW()),
            ('Web Development', NOW()),
            ('Mobile Development', NOW()),
            ('UI/UX Design', NOW()),
            ('Research', NOW()),
            ('Other', NOW())");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE specialization');
    }
}
