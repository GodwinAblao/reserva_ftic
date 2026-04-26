<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260222120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add suggested_facility_id to reservation';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reservation ADD suggested_facility_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE reservation ADD CONSTRAINT FK_RESERVATION_SUGGESTED_FACILITY FOREIGN KEY (suggested_facility_id) REFERENCES facility (id)');
        $this->addSql('CREATE INDEX IDX_RESERVATION_SUGGESTED_FACILITY ON reservation (suggested_facility_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reservation DROP FOREIGN KEY FK_RESERVATION_SUGGESTED_FACILITY');
        $this->addSql('DROP INDEX IDX_RESERVATION_SUGGESTED_FACILITY ON reservation');
        $this->addSql('ALTER TABLE reservation DROP suggested_facility_id');
    }
}
