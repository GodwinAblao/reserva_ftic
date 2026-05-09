<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260509150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add embeddedLink field to research_content table for News content with embedded media';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE research_content ADD embedded_link LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE research_content DROP embedded_link');
    }
}
