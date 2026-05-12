<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260512082900 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add parent_id column to facility table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE facility ADD parent_id INT NULL AFTER description');
        $this->addSql('ALTER TABLE facility ADD CONSTRAINT fk_facility_parent FOREIGN KEY (parent_id) REFERENCES facility(id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE facility DROP FOREIGN KEY fk_facility_parent');
        $this->addSql('ALTER TABLE facility DROP COLUMN parent_id');
    }
}
