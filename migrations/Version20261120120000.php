<?php

declare(strict_types=1);

namespace Application\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261120120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create mentor_custom_request table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE mentor_custom_request (id INT AUTO_INCREMENT NOT NULL, student_id INT NOT NULL, mentor_profile_id INT NOT NULL, message LONGTEXT NOT NULL, status VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL, mentor_response LONGTEXT DEFAULT NULL, INDEX IDX_3A5F8C4FCB944F1A (student_id), INDEX IDX_3A5F8C4C20F29E8B (mentor_profile_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE mentor_custom_request ADD CONSTRAINT FK_3A5F8C4FCB944F1A FOREIGN KEY (student_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE mentor_custom_request ADD CONSTRAINT FK_3A5F8C4C20F29E8B FOREIGN KEY (mentor_profile_id) REFERENCES mentor_profile (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mentor_custom_request DROP FOREIGN KEY FK_3A5F8C4FCB944F1A');
        $this->addSql('ALTER TABLE mentor_custom_request DROP FOREIGN KEY FK_3A5F8C4C20F29E8B');
        $this->addSql('DROP TABLE mentor_custom_request');
    }
}

