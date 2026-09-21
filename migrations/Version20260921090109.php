<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260921090109 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute sla_breach_alert_sent à support_threads (lot P4 : alerte Slack de dépassement SLA)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE support_threads ADD sla_breach_alert_sent BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE support_threads DROP sla_breach_alert_sent');
    }
}
