<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260609111530 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add event_purpose, event_purpose_other, and institutional_event fields to reservation table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reservation ADD event_purpose VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE reservation ADD event_purpose_other TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE reservation ADD institutional_event BOOLEAN DEFAULT FALSE NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reservation DROP event_purpose');
        $this->addSql('ALTER TABLE reservation DROP event_purpose_other');
        $this->addSql('ALTER TABLE reservation DROP institutional_event');
    }
}
