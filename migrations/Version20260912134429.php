<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ajoute la fréquence pilotable depuis le back-office (liste fermée de
 * {@see \App\Domain\Scheduler\Enum\CronFrequency}) pour chaque tâche planifiée.
 */
final class Version20260912134429 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute cron_definitions.frequency (fréquence pilotable depuis le back-office)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cron_definitions ADD frequency VARCHAR(30) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cron_definitions DROP frequency');
    }
}
