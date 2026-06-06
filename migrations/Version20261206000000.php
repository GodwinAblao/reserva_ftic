<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261206000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add contactNumber, yearsOfExperience, experienceUnit, supportingDescription to mentor_application';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mentor_application ADD COLUMN IF NOT EXISTS contact_number VARCHAR(50) DEFAULT NULL');
        $this->addSql('ALTER TABLE mentor_application ADD COLUMN IF NOT EXISTS years_of_experience INT DEFAULT NULL');
        $this->addSql('ALTER TABLE mentor_application ADD COLUMN IF NOT EXISTS experience_unit VARCHAR(50) DEFAULT NULL');
        $this->addSql('ALTER TABLE mentor_application ADD COLUMN IF NOT EXISTS supporting_description TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mentor_application DROP COLUMN IF EXISTS contact_number');
        $this->addSql('ALTER TABLE mentor_application DROP COLUMN IF EXISTS years_of_experience');
        $this->addSql('ALTER TABLE mentor_application DROP COLUMN IF EXISTS experience_unit');
        $this->addSql('ALTER TABLE mentor_application DROP COLUMN IF EXISTS supporting_description');
    }
}
