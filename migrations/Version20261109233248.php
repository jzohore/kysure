<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261109233248 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Lot 5 adéquation : rapports d\'adéquation figés (validation CGP, empreinte, révocation, PDF).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE adequacy_reports (id UUID NOT NULL, slug_id VARCHAR(255) NOT NULL, content_hash VARCHAR(64) NOT NULL, validated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, validated_by_name VARCHAR(255) NOT NULL, revoked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, revoked_by_name VARCHAR(255) DEFAULT NULL, revoke_reason TEXT DEFAULT NULL, pdf_storage_path VARCHAR(255) DEFAULT NULL, content JSON NOT NULL, version INT NOT NULL, workspace_id UUID NOT NULL, compliance_folder_id UUID NOT NULL, client_id UUID NOT NULL, investor_profile_id UUID NOT NULL, validated_by_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_77DADF36311966CE ON adequacy_reports (slug_id)');
        $this->addSql('CREATE INDEX idx_adequacy_report_profile ON adequacy_reports (investor_profile_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_adequacy_report_folder_version ON adequacy_reports (compliance_folder_id, version)');
        $this->addSql('CREATE INDEX IDX_77DADF3682D40A1F ON adequacy_reports (workspace_id)');
        $this->addSql('CREATE INDEX IDX_77DADF365E22A3B9 ON adequacy_reports (compliance_folder_id)');
        $this->addSql('CREATE INDEX IDX_77DADF3619EB6921 ON adequacy_reports (client_id)');
        $this->addSql('CREATE INDEX IDX_77DADF36C69DE5E5 ON adequacy_reports (validated_by_id)');
        $this->addSql('ALTER TABLE adequacy_reports ADD CONSTRAINT FK_77DADF3682D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE adequacy_reports ADD CONSTRAINT FK_77DADF365E22A3B9 FOREIGN KEY (compliance_folder_id) REFERENCES compliance_folders (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE adequacy_reports ADD CONSTRAINT FK_77DADF3619EB6921 FOREIGN KEY (client_id) REFERENCES "clients" (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE adequacy_reports ADD CONSTRAINT FK_77DADF36D09FF21A FOREIGN KEY (investor_profile_id) REFERENCES suitability_validated_investor_profiles (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE adequacy_reports ADD CONSTRAINT FK_77DADF36C69DE5E5 FOREIGN KEY (validated_by_id) REFERENCES "users" (id) ON DELETE RESTRICT NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE adequacy_reports');
    }
}
