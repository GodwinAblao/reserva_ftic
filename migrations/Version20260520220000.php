<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260520220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add education, availability_start, availability_end columns to mentor_profile table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE mentor_profile
                ADD IF NOT EXISTS education VARCHAR(255) DEFAULT NULL,
                ADD IF NOT EXISTS availability_start VARCHAR(100) DEFAULT NULL,
                ADD IF NOT EXISTS availability_end VARCHAR(100) DEFAULT NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE mentor_profile
                DROP COLUMN IF EXISTS education,
                DROP COLUMN IF EXISTS availability_start,
                DROP COLUMN IF EXISTS availability_end
        SQL);
    }
}
