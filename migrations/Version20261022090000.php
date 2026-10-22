<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261022090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'OCR des pièces : date de la dernière analyse automatique aboutie (affichée au conseiller).';
    }

    public function up(Schema $schema): void
    {
        // Additif : colonne nullable, pas de verrou long. Les analyses antérieures restent sans date (non reconstituable).
        $this->addSql('ALTER TABLE compliance_documents ADD ocr_analyzed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE compliance_documents DROP ocr_analyzed_at');
    }
}
