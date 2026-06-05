<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261205000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add performance indexes for dashboard polling, notifications, mentoring, and research lists';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_reservation_status_created_at ON reservation (status, created_at)');
        $this->addSql('CREATE INDEX idx_reservation_date_status ON reservation (reservation_date, status)');
        $this->addSql('CREATE INDEX idx_reservation_created_at ON reservation (created_at)');
        $this->addSql('CREATE INDEX idx_notifications_user_read_id ON notifications (user_id, is_read, id)');
        $this->addSql('CREATE INDEX idx_notifications_user_status_id ON notifications (user_id, status, id)');
        $this->addSql('CREATE INDEX idx_notifications_user_created_at ON notifications (user_id, created_at)');
        $this->addSql('CREATE INDEX idx_mentor_custom_request_status_created ON mentor_custom_request (status, created_at)');
        $this->addSql('CREATE INDEX idx_mentor_custom_request_created_at ON mentor_custom_request (created_at)');
        $this->addSql('CREATE INDEX idx_mentor_application_status_created ON mentor_application (status, created_at)');
        $this->addSql('CREATE INDEX idx_mentoring_appointment_scheduled_at ON mentoring_appointment (scheduled_at)');
        $this->addSql('CREATE INDEX idx_research_visibility_type_created ON research_content (visibility, type, created_at)');
    }

    public function down(Schema $schema): void
    {
        if ($this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform) {
            $this->addSql('DROP INDEX idx_research_visibility_type_created');
            $this->addSql('DROP INDEX idx_mentoring_appointment_scheduled_at');
            $this->addSql('DROP INDEX idx_mentor_application_status_created');
            $this->addSql('DROP INDEX idx_mentor_custom_request_created_at');
            $this->addSql('DROP INDEX idx_mentor_custom_request_status_created');
            $this->addSql('DROP INDEX idx_notifications_user_created_at');
            $this->addSql('DROP INDEX idx_notifications_user_status_id');
            $this->addSql('DROP INDEX idx_notifications_user_read_id');
            $this->addSql('DROP INDEX idx_reservation_created_at');
            $this->addSql('DROP INDEX idx_reservation_date_status');
            $this->addSql('DROP INDEX idx_reservation_status_created_at');
            return;
        }

        $this->addSql('DROP INDEX idx_research_visibility_type_created ON research_content');
        $this->addSql('DROP INDEX idx_mentoring_appointment_scheduled_at ON mentoring_appointment');
        $this->addSql('DROP INDEX idx_mentor_application_status_created ON mentor_application');
        $this->addSql('DROP INDEX idx_mentor_custom_request_created_at ON mentor_custom_request');
        $this->addSql('DROP INDEX idx_mentor_custom_request_status_created ON mentor_custom_request');
        $this->addSql('DROP INDEX idx_notifications_user_created_at ON notifications');
        $this->addSql('DROP INDEX idx_notifications_user_status_id ON notifications');
        $this->addSql('DROP INDEX idx_notifications_user_read_id ON notifications');
        $this->addSql('DROP INDEX idx_reservation_created_at ON reservation');
        $this->addSql('DROP INDEX idx_reservation_date_status ON reservation');
        $this->addSql('DROP INDEX idx_reservation_status_created_at ON reservation');
    }
}
