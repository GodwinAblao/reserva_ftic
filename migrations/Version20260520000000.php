<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260520000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add reservation_status_log table for admin/super-admin status audit trail';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE reservation_status_log (
                id INT AUTO_INCREMENT NOT NULL,
                reservation_id INT NOT NULL,
                changed_by_id INT NOT NULL,
                previous_status VARCHAR(50) NOT NULL,
                new_status VARCHAR(50) NOT NULL,
                actor_role_label VARCHAR(30) NOT NULL,
                action VARCHAR(30) NOT NULL,
                note LONGTEXT DEFAULT NULL,
                changed_at DATETIME NOT NULL,
                INDEX idx_reservation_status_log_changed_at (changed_at),
                INDEX IDX_RESERVATION_STATUS_LOG_RESERVATION (reservation_id),
                INDEX IDX_RESERVATION_STATUS_LOG_USER (changed_by_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
        $this->addSql('ALTER TABLE reservation_status_log ADD CONSTRAINT FK_RSL_RESERVATION FOREIGN KEY (reservation_id) REFERENCES reservation (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE reservation_status_log ADD CONSTRAINT FK_RSL_USER FOREIGN KEY (changed_by_id) REFERENCES `user` (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE reservation_status_log');
    }
}
