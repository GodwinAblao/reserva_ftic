<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260428143000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add user verification fields for registration confirmation';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` ADD is_verified TINYINT(1) NOT NULL, ADD verification_code VARCHAR(10) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` DROP is_verified, DROP verification_code');
    }
}
