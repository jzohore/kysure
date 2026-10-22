<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261022100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Décision du conseiller sur les pièces : motif codifié, auteur et date de la décision, date du signalement au client.';
    }

    public function up(Schema $schema): void
    {
        // Additif : colonnes nullables, pas de verrou long.
        $this->addSql('ALTER TABLE compliance_documents ADD rejection_reason_code VARCHAR(30) DEFAULT NULL');
        $this->addSql('ALTER TABLE compliance_documents ADD reviewed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE compliance_documents ADD reviewed_by_name VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE compliance_documents ADD correction_requested_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE compliance_documents DROP rejection_reason_code');
        $this->addSql('ALTER TABLE compliance_documents DROP reviewed_at');
        $this->addSql('ALTER TABLE compliance_documents DROP reviewed_by_name');
        $this->addSql('ALTER TABLE compliance_documents DROP correction_requested_at');
    }
}
