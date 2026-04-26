<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260222090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Replace reservation_time with reservation_start_time and reservation_end_time';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reservation ADD reservation_start_time TIME NOT NULL, ADD reservation_end_time TIME NOT NULL');
        $this->addSql("UPDATE reservation SET reservation_start_time = reservation_time, reservation_end_time = ADDTIME(reservation_time, '01:00:00') WHERE reservation_time IS NOT NULL");
        $this->addSql('ALTER TABLE reservation DROP reservation_time');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reservation ADD reservation_time TIME NOT NULL');
        $this->addSql('UPDATE reservation SET reservation_time = reservation_start_time');
        $this->addSql('ALTER TABLE reservation DROP reservation_start_time');
        $this->addSql('ALTER TABLE reservation DROP reservation_end_time');
    }
}
