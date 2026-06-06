<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260606142737 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add indexes on notifications(user_id, is_read) and notifications(user_id, id) for fast poll queries';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_notif_user_unread ON notifications (user_id, is_read)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_notif_user_id ON notifications (user_id, id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_notif_user_unread');
        $this->addSql('DROP INDEX IF EXISTS idx_notif_user_id');
    }
}
