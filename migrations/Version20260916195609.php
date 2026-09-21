<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260916195609 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crée suitability_validated_investor_profiles (lot 2 — figeage et validation CGP du profil investisseur)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
                CREATE TABLE suitability_validated_investor_profiles (
                  id UUID NOT NULL,
                  slug_id VARCHAR(255) NOT NULL,
                  content_hash VARCHAR(64) NOT NULL,
                  validated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                  validated_by_name VARCHAR(255) NOT NULL,
                  revoked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                  revoked_by_name VARCHAR(255) DEFAULT NULL,
                  revoke_reason TEXT DEFAULT NULL,
                  overridden_profile_level SMALLINT DEFAULT NULL,
                  override_reason TEXT DEFAULT NULL,
                  content JSON NOT NULL,
                  version INT DEFAULT 1 NOT NULL,
                  workspace_id UUID NOT NULL,
                  client_id UUID NOT NULL,
                  assessment_id UUID NOT NULL,
                  validated_by_id UUID NOT NULL,
                  PRIMARY KEY (id)
                )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX UNIQ_E4FAD15D311966CE ON suitability_validated_investor_profiles (slug_id)');
        $this->addSql('CREATE INDEX IDX_E4FAD15D82D40A1F ON suitability_validated_investor_profiles (workspace_id)');
        $this->addSql('CREATE INDEX IDX_E4FAD15D19EB6921 ON suitability_validated_investor_profiles (client_id)');
        $this->addSql('CREATE INDEX IDX_E4FAD15DDD3DD5F1 ON suitability_validated_investor_profiles (assessment_id)');
        $this->addSql('CREATE INDEX IDX_E4FAD15DC69DE5E5 ON suitability_validated_investor_profiles (validated_by_id)');
        $this->addSql(<<<'SQL'
                CREATE UNIQUE INDEX uniq_client_profile_version ON suitability_validated_investor_profiles (client_id, version)
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  suitability_validated_investor_profiles
                ADD
                  CONSTRAINT FK_E4FAD15D82D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  suitability_validated_investor_profiles
                ADD
                  CONSTRAINT FK_E4FAD15D19EB6921 FOREIGN KEY (client_id) REFERENCES "clients" (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  suitability_validated_investor_profiles
                ADD
                  CONSTRAINT FK_E4FAD15DDD3DD5F1 FOREIGN KEY (assessment_id) REFERENCES investor_profile_assessments (id) ON DELETE RESTRICT NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  suitability_validated_investor_profiles
                ADD
                  CONSTRAINT FK_E4FAD15DC69DE5E5 FOREIGN KEY (validated_by_id) REFERENCES "users" (id) ON DELETE RESTRICT NOT DEFERRABLE
            SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE suitability_validated_investor_profiles DROP CONSTRAINT FK_E4FAD15D82D40A1F');
        $this->addSql('ALTER TABLE suitability_validated_investor_profiles DROP CONSTRAINT FK_E4FAD15D19EB6921');
        $this->addSql('ALTER TABLE suitability_validated_investor_profiles DROP CONSTRAINT FK_E4FAD15DDD3DD5F1');
        $this->addSql('ALTER TABLE suitability_validated_investor_profiles DROP CONSTRAINT FK_E4FAD15DC69DE5E5');
        $this->addSql('DROP TABLE suitability_validated_investor_profiles');
    }
}
