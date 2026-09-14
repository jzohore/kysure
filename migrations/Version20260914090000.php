<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration manquante pour le bounded context Support : les tables
 * `support_threads` et `support_messages` existaient déjà en base (créées
 * hors migration) sans qu'aucun fichier ne les recrée. Un environnement
 * reconstruit depuis les migrations en serait dépourvu. Schéma repris tel
 * quel depuis la base actuelle (`pg_dump --schema-only`), aucune colonne
 * ajoutée ni retirée.
 */
final class Version20260914090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute support_threads et support_messages (migration manquante, tables déjà en usage)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE support_threads (id UUID NOT NULL, slug_id VARCHAR(255) NOT NULL, status VARCHAR(255) NOT NULL, url_context VARCHAR(255) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, category VARCHAR(100) NOT NULL, topic VARCHAR(100) NOT NULL, closure_warning_sent BOOLEAN DEFAULT false NOT NULL, workspace_id UUID NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_D2BB3521311966CE ON support_threads (slug_id)');
        $this->addSql('CREATE INDEX IDX_D2BB352182D40A1F ON support_threads (workspace_id)');
        $this->addSql('CREATE INDEX IDX_D2BB3521A76ED395 ON support_threads (user_id)');
        $this->addSql('CREATE TABLE support_messages (id UUID NOT NULL, slug_id VARCHAR(255) NOT NULL, sender_type VARCHAR(255) NOT NULL, content TEXT NOT NULL, read_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, thread_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_6FB495A9311966CE ON support_messages (slug_id)');
        $this->addSql('CREATE INDEX IDX_6FB495A9E2904019 ON support_messages (thread_id)');
        $this->addSql('ALTER TABLE support_threads ADD CONSTRAINT FK_D2BB352182D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE support_threads ADD CONSTRAINT FK_D2BB3521A76ED395 FOREIGN KEY (user_id) REFERENCES "users" (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE support_messages ADD CONSTRAINT FK_6FB495A9E2904019 FOREIGN KEY (thread_id) REFERENCES support_threads (id) NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE support_messages DROP CONSTRAINT FK_6FB495A9E2904019');
        $this->addSql('ALTER TABLE support_threads DROP CONSTRAINT FK_D2BB352182D40A1F');
        $this->addSql('ALTER TABLE support_threads DROP CONSTRAINT FK_D2BB3521A76ED395');
        $this->addSql('DROP TABLE support_messages');
        $this->addSql('DROP TABLE support_threads');
    }
}
