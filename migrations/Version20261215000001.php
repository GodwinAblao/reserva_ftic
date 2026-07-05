<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\Migrations\AbstractMigration;

final class Version20261215000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create inbox_message table for the messaging module';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof PostgreSQLPlatform) {
            $this->addSql('CREATE TABLE IF NOT EXISTS inbox_message (id SERIAL NOT NULL, sender_id INT NOT NULL, recipient_id INT NOT NULL, parent_message_id INT DEFAULT NULL, subject VARCHAR(255) NOT NULL, body TEXT NOT NULL, is_read_by_recipient BOOLEAN NOT NULL DEFAULT FALSE, is_deleted_by_sender BOOLEAN NOT NULL DEFAULT FALSE, is_deleted_by_recipient BOOLEAN NOT NULL DEFAULT FALSE, thread_id INT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
            $this->addSql('CREATE INDEX IDX_IM_SENDER ON inbox_message (sender_id)');
            $this->addSql('CREATE INDEX IDX_IM_RECIPIENT ON inbox_message (recipient_id)');
            $this->addSql('CREATE INDEX IDX_IM_PARENT ON inbox_message (parent_message_id)');
            $this->addSql('CREATE INDEX IDX_IM_THREAD ON inbox_message (thread_id)');
            $this->addSql('ALTER TABLE inbox_message ADD CONSTRAINT FK_IM_SENDER FOREIGN KEY (sender_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
            $this->addSql('ALTER TABLE inbox_message ADD CONSTRAINT FK_IM_RECIPIENT FOREIGN KEY (recipient_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
            $this->addSql('ALTER TABLE inbox_message ADD CONSTRAINT FK_IM_PARENT FOREIGN KEY (parent_message_id) REFERENCES inbox_message (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        } else {
            $this->addSql('CREATE TABLE inbox_message (id INT AUTO_INCREMENT NOT NULL, sender_id INT NOT NULL, recipient_id INT NOT NULL, parent_message_id INT DEFAULT NULL, subject VARCHAR(255) NOT NULL, body LONGTEXT NOT NULL, is_read_by_recipient TINYINT(1) NOT NULL DEFAULT 0, is_deleted_by_sender TINYINT(1) NOT NULL DEFAULT 0, is_deleted_by_recipient TINYINT(1) NOT NULL DEFAULT 0, thread_id INT DEFAULT NULL, created_at DATETIME NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');
            $this->addSql('ALTER TABLE inbox_message ADD CONSTRAINT FK_IM_SENDER FOREIGN KEY (sender_id) REFERENCES user (id) ON DELETE CASCADE');
            $this->addSql('ALTER TABLE inbox_message ADD CONSTRAINT FK_IM_RECIPIENT FOREIGN KEY (recipient_id) REFERENCES user (id) ON DELETE CASCADE');
            $this->addSql('ALTER TABLE inbox_message ADD CONSTRAINT FK_IM_PARENT FOREIGN KEY (parent_message_id) REFERENCES inbox_message (id) ON DELETE SET NULL');
        }
    }

    public function down(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        if ($platform instanceof PostgreSQLPlatform) {
            $this->addSql('ALTER TABLE inbox_message DROP CONSTRAINT IF EXISTS FK_IM_SENDER');
            $this->addSql('ALTER TABLE inbox_message DROP CONSTRAINT IF EXISTS FK_IM_RECIPIENT');
            $this->addSql('ALTER TABLE inbox_message DROP CONSTRAINT IF EXISTS FK_IM_PARENT');
            $this->addSql('DROP TABLE IF EXISTS inbox_message');
        } else {
            $this->addSql('ALTER TABLE inbox_message DROP FOREIGN KEY FK_IM_SENDER');
            $this->addSql('ALTER TABLE inbox_message DROP FOREIGN KEY FK_IM_RECIPIENT');
            $this->addSql('ALTER TABLE inbox_message DROP FOREIGN KEY FK_IM_PARENT');
            $this->addSql('DROP TABLE inbox_message');
        }
    }
}