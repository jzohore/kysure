<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261105120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Lot 3 adéquation : vérifications d\'adéquation figées et classification SFDR des produits.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE adequacy_product_checks (id UUID NOT NULL, slug_id VARCHAR(255) NOT NULL, worst_severity VARCHAR(20) NOT NULL, content_hash VARCHAR(64) NOT NULL, checked_by_name VARCHAR(255) NOT NULL, checked_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, amount_in_cents INT NOT NULL, content JSON NOT NULL, justification TEXT DEFAULT NULL, workspace_id UUID NOT NULL, compliance_folder_id UUID NOT NULL, investor_profile_id UUID NOT NULL, product_id UUID NOT NULL, checked_by_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_2EC68A8B311966CE ON adequacy_product_checks (slug_id)');
        $this->addSql('CREATE INDEX idx_adequacy_check_folder ON adequacy_product_checks (compliance_folder_id, checked_at)');
        $this->addSql('CREATE INDEX IDX_2EC68A8B82D40A1F ON adequacy_product_checks (workspace_id)');
        $this->addSql('CREATE INDEX IDX_2EC68A8B5E22A3B9 ON adequacy_product_checks (compliance_folder_id)');
        $this->addSql('CREATE INDEX IDX_2EC68A8BD09FF21A ON adequacy_product_checks (investor_profile_id)');
        $this->addSql('CREATE INDEX IDX_2EC68A8B4584665A ON adequacy_product_checks (product_id)');
        $this->addSql('CREATE INDEX IDX_2EC68A8B2199DB86 ON adequacy_product_checks (checked_by_id)');
        $this->addSql('ALTER TABLE adequacy_product_checks ADD CONSTRAINT FK_2EC68A8B82D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE adequacy_product_checks ADD CONSTRAINT FK_2EC68A8B5E22A3B9 FOREIGN KEY (compliance_folder_id) REFERENCES compliance_folders (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE adequacy_product_checks ADD CONSTRAINT FK_2EC68A8BD09FF21A FOREIGN KEY (investor_profile_id) REFERENCES suitability_validated_investor_profiles (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE adequacy_product_checks ADD CONSTRAINT FK_2EC68A8B4584665A FOREIGN KEY (product_id) REFERENCES financial_products (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE adequacy_product_checks ADD CONSTRAINT FK_2EC68A8B2199DB86 FOREIGN KEY (checked_by_id) REFERENCES "users" (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE financial_products ADD sfdr_classification VARCHAR(20) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE financial_products DROP sfdr_classification');
        $this->addSql('DROP TABLE adequacy_product_checks');
    }
}
