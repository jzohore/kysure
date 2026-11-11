<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261111100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Avenant au DER : accord du client pour la remise électronique et suivi de la demande.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE compliance_electronic_delivery_consents (id UUID NOT NULL, slug_id VARCHAR(255) NOT NULL, statement_text TEXT NOT NULL, statement_version VARCHAR(20) NOT NULL, consented_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, workspace_id UUID NOT NULL, compliance_folder_id UUID NOT NULL, client_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_691288E4311966CE ON compliance_electronic_delivery_consents (slug_id)');
        $this->addSql('CREATE INDEX idx_edc_folder ON compliance_electronic_delivery_consents (compliance_folder_id, consented_at)');
        $this->addSql('CREATE INDEX IDX_691288E482D40A1F ON compliance_electronic_delivery_consents (workspace_id)');
        $this->addSql('CREATE INDEX IDX_691288E45E22A3B9 ON compliance_electronic_delivery_consents (compliance_folder_id)');
        $this->addSql('CREATE INDEX IDX_691288E419EB6921 ON compliance_electronic_delivery_consents (client_id)');
        $this->addSql('ALTER TABLE compliance_electronic_delivery_consents ADD CONSTRAINT FK_691288E482D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE compliance_electronic_delivery_consents ADD CONSTRAINT FK_691288E45E22A3B9 FOREIGN KEY (compliance_folder_id) REFERENCES compliance_folders (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE compliance_electronic_delivery_consents ADD CONSTRAINT FK_691288E419EB6921 FOREIGN KEY (client_id) REFERENCES "clients" (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE compliance_folders ADD electronic_delivery_consent_requested_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE compliance_folders DROP electronic_delivery_consent_requested_at');
        $this->addSql('DROP TABLE compliance_electronic_delivery_consents');
    }
}
