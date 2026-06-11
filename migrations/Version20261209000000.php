<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261209000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Enable row level security on landing page content';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE landing_page_content ENABLE ROW LEVEL SECURITY');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE landing_page_content DISABLE ROW LEVEL SECURITY');
    }
}
