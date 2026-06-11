<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261208000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add editable social links section to the landing page content';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE landing_page_content ADD COLUMN IF NOT EXISTS social_section_title VARCHAR(255) NOT NULL DEFAULT 'Follow Us'");
        $this->addSql("ALTER TABLE landing_page_content ADD COLUMN IF NOT EXISTS social_section_subtitle VARCHAR(255) NOT NULL DEFAULT 'Stay connected with FTIC on our official social channels'");
        $this->addSql("ALTER TABLE landing_page_content ADD COLUMN IF NOT EXISTS social_links_json TEXT NOT NULL DEFAULT '[]'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE landing_page_content DROP COLUMN IF EXISTS social_links_json');
        $this->addSql('ALTER TABLE landing_page_content DROP COLUMN IF EXISTS social_section_subtitle');
        $this->addSql('ALTER TABLE landing_page_content DROP COLUMN IF EXISTS social_section_title');
    }
}
