<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260916160519 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crée investor_profile_assessments (lot 1 — questionnaire profil investisseur auto-administré)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
                CREATE TABLE investor_profile_assessments (
                  id UUID NOT NULL,
                  slug_id VARCHAR(255) NOT NULL,
                  created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  status VARCHAR(255) NOT NULL,
                  answers JSON NOT NULL,
                  submitted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                  score_snapshot JSON DEFAULT NULL,
                  workspace_id UUID NOT NULL,
                  client_id UUID NOT NULL,
                  PRIMARY KEY (id)
                )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX UNIQ_BAF984DD311966CE ON investor_profile_assessments (slug_id)');
        $this->addSql('CREATE INDEX IDX_BAF984DD82D40A1F ON investor_profile_assessments (workspace_id)');
        $this->addSql('CREATE INDEX IDX_BAF984DD19EB6921 ON investor_profile_assessments (client_id)');
        $this->addSql(<<<'SQL'
                CREATE INDEX idx_investor_profile_assessments_client_status ON investor_profile_assessments (client_id, status)
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  investor_profile_assessments
                ADD
                  CONSTRAINT FK_BAF984DD82D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  investor_profile_assessments
                ADD
                  CONSTRAINT FK_BAF984DD19EB6921 FOREIGN KEY (client_id) REFERENCES "clients" (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE investor_profile_assessments DROP CONSTRAINT FK_BAF984DD82D40A1F');
        $this->addSql('ALTER TABLE investor_profile_assessments DROP CONSTRAINT FK_BAF984DD19EB6921');
        $this->addSql('DROP TABLE investor_profile_assessments');
    }
}
