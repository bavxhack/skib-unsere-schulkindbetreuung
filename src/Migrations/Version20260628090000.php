<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260628090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add city module switch for parent sick reports';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE stadt ADD parent_sick_reports_enabled TINYINT(1) DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE stadt DROP parent_sick_reports_enabled');
    }
}
