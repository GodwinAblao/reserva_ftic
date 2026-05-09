<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260509084221 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add research-specific fields to research_content table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE research_content ADD repository_type VARCHAR(100) DEFAULT NULL, ADD authors LONGTEXT DEFAULT NULL, ADD abstract LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE research_content DROP repository_type, DROP authors, DROP abstract');
    }
}
