<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260604010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add cancellation_reason column to mentor_custom_request table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE mentor_custom_request ADD cancellation_reason TEXT DEFAULT NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE mentor_custom_request DROP cancellation_reason
        SQL);
    }
}
