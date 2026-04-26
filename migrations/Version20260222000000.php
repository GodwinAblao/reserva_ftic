<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260222000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add reservation table for facility booking system';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE reservation (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, facility_id INT NOT NULL, name VARCHAR(255) NOT NULL, email VARCHAR(255) NOT NULL, contact VARCHAR(20) NOT NULL, reservation_date DATETIME NOT NULL, reservation_time TIME NOT NULL, capacity INT NOT NULL, purpose LONGTEXT DEFAULT NULL, status VARCHAR(50) NOT NULL DEFAULT \'Pending\', created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, rejection_reason VARCHAR(255) DEFAULT NULL, INDEX IDX_42C84955A76ED395 (user_id), INDEX IDX_42C849556F96B3A5 (facility_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE reservation ADD CONSTRAINT FK_42C84955A76ED395 FOREIGN KEY (user_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE reservation ADD CONSTRAINT FK_42C849556F96B3A5 FOREIGN KEY (facility_id) REFERENCES facility (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE reservation DROP FOREIGN KEY FK_42C84955A76ED395');
        $this->addSql('ALTER TABLE reservation DROP FOREIGN KEY FK_42C849556F96B3A5');
        $this->addSql('DROP TABLE reservation');
    }
}
