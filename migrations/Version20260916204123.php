<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260916204123 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute last_activity_at et reminder_sent_at à investor_profile_assessments (relance email des questionnaires en pause)';
    }

    public function up(Schema $schema): void
    {
        // Colonne ajoutée nullable puis backfillée depuis created_at (table non vide en prod),
        // avant de la passer NOT NULL — pas de valeur par défaut pertinente pour une table déjà peuplée.
        $this->addSql('ALTER TABLE investor_profile_assessments ADD last_activity_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('UPDATE investor_profile_assessments SET last_activity_at = created_at WHERE last_activity_at IS NULL');
        $this->addSql('ALTER TABLE investor_profile_assessments ALTER COLUMN last_activity_at SET NOT NULL');
        $this->addSql('ALTER TABLE investor_profile_assessments ADD reminder_sent_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE investor_profile_assessments DROP last_activity_at');
        $this->addSql('ALTER TABLE investor_profile_assessments DROP reminder_sent_at');
    }
}
