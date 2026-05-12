<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260512130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add facility image gallery and facility schedule blocks';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE facility_image (id INT AUTO_INCREMENT NOT NULL, facility_id INT NOT NULL, path VARCHAR(255) NOT NULL, caption VARCHAR(255) DEFAULT NULL, position INT NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_39CDA0059F7E4405 (facility_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE facility_schedule_block (id INT AUTO_INCREMENT NOT NULL, facility_id INT NOT NULL, title VARCHAR(255) NOT NULL, type VARCHAR(50) NOT NULL, block_date DATE NOT NULL, start_time TIME NOT NULL, end_time TIME NOT NULL, source VARCHAR(255) DEFAULT NULL, notes LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_77E5F95D9F7E4405 (facility_id), INDEX facility_schedule_block_lookup_idx (facility_id, block_date, start_time, end_time), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE facility_image ADD CONSTRAINT FK_39CDA0059F7E4405 FOREIGN KEY (facility_id) REFERENCES facility (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE facility_schedule_block ADD CONSTRAINT FK_77E5F95D9F7E4405 FOREIGN KEY (facility_id) REFERENCES facility (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE facility_image DROP FOREIGN KEY FK_39CDA0059F7E4405');
        $this->addSql('ALTER TABLE facility_schedule_block DROP FOREIGN KEY FK_77E5F95D9F7E4405');
        $this->addSql('DROP TABLE facility_image');
        $this->addSql('DROP TABLE facility_schedule_block');
    }
}
