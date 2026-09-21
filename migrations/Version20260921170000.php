<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260921170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crée client_files (fiche patrimoniale EER — lot 1 du chantier rapport d\'adéquation)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE client_files (id UUID NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, civility VARCHAR(255) DEFAULT NULL, birth_date DATE DEFAULT NULL, birth_place VARCHAR(150) DEFAULT NULL, nationality VARCHAR(100) DEFAULT NULL, marital_status VARCHAR(255) DEFAULT NULL, children_count SMALLINT DEFAULT NULL, professional_status VARCHAR(255) DEFAULT NULL, profession VARCHAR(150) DEFAULT NULL, employer VARCHAR(150) DEFAULT NULL, professional_seniority_years SMALLINT DEFAULT NULL, annual_income_in_cents INT DEFAULT NULL, annual_expenses_in_cents INT DEFAULT NULL, outstanding_debt_in_cents INT DEFAULT NULL, investment_capacity_in_cents INT DEFAULT NULL, net_worth_lines JSON NOT NULL, objectives JSON NOT NULL, compliance_folder_id UUID NOT NULL, workspace_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_A558DCEE5E22A3B9 ON client_files (compliance_folder_id)');
        $this->addSql('CREATE INDEX IDX_A558DCEE82D40A1F ON client_files (workspace_id)');
        $this->addSql('ALTER TABLE client_files ADD CONSTRAINT FK_CLIENT_FILES_COMPLIANCE_FOLDER FOREIGN KEY (compliance_folder_id) REFERENCES compliance_folders (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE client_files ADD CONSTRAINT FK_CLIENT_FILES_WORKSPACE FOREIGN KEY (workspace_id) REFERENCES workspaces (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE client_files DROP CONSTRAINT FK_CLIENT_FILES_COMPLIANCE_FOLDER');
        $this->addSql('ALTER TABLE client_files DROP CONSTRAINT FK_CLIENT_FILES_WORKSPACE');
        $this->addSql('DROP TABLE client_files');
    }
}
