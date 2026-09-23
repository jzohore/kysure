<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260922090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crée financial_products (catalogue produits du cabinet — lot 2 du chantier rapport d\'adéquation)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE financial_products (id UUID NOT NULL, slug_id VARCHAR(255) NOT NULL, name VARCHAR(255) NOT NULL, isin VARCHAR(12) DEFAULT NULL, family VARCHAR(255) NOT NULL, sri_level SMALLINT NOT NULL, minimum_horizon_years SMALLINT NOT NULL, annual_fees_basis_points INT NOT NULL, target_investor_profiles JSON NOT NULL, archived_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, workspace_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_1E8F115E311966CE ON financial_products (slug_id)');
        $this->addSql('CREATE INDEX IDX_1E8F115E82D40A1F ON financial_products (workspace_id)');
        $this->addSql('ALTER TABLE financial_products ADD CONSTRAINT FK_FINANCIAL_PRODUCTS_WORKSPACE FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE financial_products DROP CONSTRAINT FK_FINANCIAL_PRODUCTS_WORKSPACE');
        $this->addSql('DROP TABLE financial_products');
    }
}
