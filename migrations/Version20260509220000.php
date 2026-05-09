<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260509220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add external_link field to research_content for Article external URLs';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE research_content ADD external_link LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE research_content DROP external_link');
    }
}
