<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261019100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'OCR des pièces déposées : horodatage de l\'échec définitif de l\'analyse automatique.';
    }

    public function up(Schema $schema): void
    {
        // Additif : colonne nullable, pas de verrou long.
        $this->addSql('ALTER TABLE compliance_documents ADD ocr_unavailable_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE compliance_documents DROP ocr_unavailable_at');
    }
}
