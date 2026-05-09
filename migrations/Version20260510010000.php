<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260510010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Expand mentor custom requests for admin-assisted mentor matching workflow';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mentor_custom_request DROP FOREIGN KEY FK_3A5F8C4C20F29E8B');
        $this->addSql('ALTER TABLE mentor_custom_request CHANGE mentor_profile_id mentor_profile_id INT DEFAULT NULL, CHANGE status status VARCHAR(50) NOT NULL');
        $this->addSql('ALTER TABLE mentor_custom_request ADD updated_at DATETIME DEFAULT NULL, ADD full_name VARCHAR(255) DEFAULT NULL, ADD department_course VARCHAR(255) DEFAULT NULL, ADD preferred_expertise VARCHAR(255) DEFAULT NULL, ADD preferred_schedule VARCHAR(255) DEFAULT NULL, ADD assigned_mentor_name VARCHAR(255) DEFAULT NULL, ADD assigned_mentor_expertise VARCHAR(255) DEFAULT NULL, ADD available_dates VARCHAR(255) DEFAULT NULL, ADD available_time VARCHAR(255) DEFAULT NULL, ADD meeting_method VARCHAR(50) DEFAULT NULL, ADD admin_instructions LONGTEXT DEFAULT NULL, ADD responded_at DATETIME DEFAULT NULL');
        $this->addSql('UPDATE mentor_custom_request SET updated_at = created_at WHERE updated_at IS NULL');
        $this->addSql('ALTER TABLE mentor_custom_request ADD CONSTRAINT FK_3A5F8C4C20F29E8B FOREIGN KEY (mentor_profile_id) REFERENCES mentor_profile (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mentor_custom_request DROP FOREIGN KEY FK_3A5F8C4C20F29E8B');
        $this->addSql('ALTER TABLE mentor_custom_request DROP updated_at, DROP full_name, DROP department_course, DROP preferred_expertise, DROP preferred_schedule, DROP assigned_mentor_name, DROP assigned_mentor_expertise, DROP available_dates, DROP available_time, DROP meeting_method, DROP admin_instructions, DROP responded_at');
        $this->addSql('ALTER TABLE mentor_custom_request CHANGE status status VARCHAR(20) NOT NULL');
        $this->addSql('ALTER TABLE mentor_custom_request ADD CONSTRAINT FK_3A5F8C4C20F29E8B FOREIGN KEY (mentor_profile_id) REFERENCES mentor_profile (id)');
    }
}
