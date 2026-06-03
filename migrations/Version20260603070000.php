<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260603070000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Make user_id nullable in reservation table for dummy data';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE reservation ALTER COLUMN user_id DROP NOT NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE reservation ALTER COLUMN user_id SET NOT NULL
        SQL);
    }
}
