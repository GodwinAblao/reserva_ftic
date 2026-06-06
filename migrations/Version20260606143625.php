<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260606143625 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add created_at DESC indexes for ORDER BY queries on reservation, mentor_custom_request, mentor_application, mentoring_appointment';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_reservation_created_at ON reservation (created_at DESC)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_mentor_custom_req_created_at ON mentor_custom_request (created_at DESC)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_mentor_application_created_at ON mentor_application (created_at DESC)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_mentoring_appt_scheduled_at ON mentoring_appointment (scheduled_at DESC)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_mentor_profile_points ON mentor_profile (engagement_points DESC)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_reservation_created_at');
        $this->addSql('DROP INDEX IF EXISTS idx_mentor_custom_req_created_at');
        $this->addSql('DROP INDEX IF EXISTS idx_mentor_application_created_at');
        $this->addSql('DROP INDEX IF EXISTS idx_mentoring_appt_scheduled_at');
        $this->addSql('DROP INDEX IF EXISTS idx_mentor_profile_points');
    }
}
