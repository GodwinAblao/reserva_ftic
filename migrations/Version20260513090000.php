<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260513090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Seed the admin account for the ROLE_ADMIN side';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO `user` (`email`, `roles`, `password`, `is_verified`, `verification_code`, `first_name`, `middle_name`, `last_name`, `degree`, `degree_name`, `profile_picture`)
            VALUES ('admin@fit.edu.ph', '["ROLE_ADMIN"]', '$2y$10$kavRT7C4.meNbM8Pt.Eu5.a5/oSjTQ6wso5qUrx/8X7BpPIpOLRmq', 1, NULL, 'Admin', NULL, 'User', NULL, NULL, NULL)
            ON DUPLICATE KEY UPDATE `roles` = '["ROLE_ADMIN"]', `is_verified` = 1
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM `user` WHERE `email` = 'admin@fit.edu.ph' AND `roles` = '[\"ROLE_ADMIN\"]'");
    }
}
