<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ajoute la persistance des tâches planifiées (symfony/scheduler) : état
 * d'activation par tâche (`cron_definitions`) et historique d'exécution
 * (`cron_execution_logs`) pour l'interface d'administration.
 */
final class Version20260912064918 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute cron_definitions et cron_execution_logs (gestion des tâches planifiées)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE cron_definitions (id UUID NOT NULL, enabled BOOLEAN NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, job VARCHAR(100) NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_CAB2257FFBD8E0F8 ON cron_definitions (job)');
        $this->addSql('CREATE TABLE cron_execution_logs (id UUID NOT NULL, started_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, finished_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, status VARCHAR(20) NOT NULL, error_message TEXT DEFAULT NULL, job VARCHAR(100) NOT NULL, triggered_by VARCHAR(20) NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_6F96C30CFBD8E0F8 ON cron_execution_logs (job)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE cron_definitions');
        $this->addSql('DROP TABLE cron_execution_logs');
    }
}
