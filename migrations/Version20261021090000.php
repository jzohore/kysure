<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261021090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'OCR des pièces : horodatage de la relance manuelle de l\'analyse (affichage « analyse en cours »).';
    }

    public function up(Schema $schema): void
    {
        // Additif : colonne nullable, pas de verrou long.
        $this->addSql('ALTER TABLE compliance_documents ADD ocr_requested_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE compliance_documents DROP ocr_requested_at');
    }
}
