<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261017193550 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Élargit les colonnes chiffrées (IV + tag + base64 dépassaient les anciennes longueurs).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE compliance_folders ALTER first_name TYPE VARCHAR(255)');
        $this->addSql('ALTER TABLE compliance_folders ALTER last_name TYPE VARCHAR(255)');
        $this->addSql('ALTER TABLE compliance_folders ALTER email TYPE VARCHAR(512)');
        $this->addSql('ALTER TABLE compliance_folders ALTER address TYPE VARCHAR(1024)');
        $this->addSql('ALTER TABLE support_messages ALTER attachment_filename TYPE VARCHAR(512)');
    }

    public function down(Schema $schema): void
    {
        // ⚠️ Rétrécir échouera si des valeurs chiffrées dépassent déjà les anciennes longueurs.
        $this->addSql('ALTER TABLE compliance_folders ALTER first_name TYPE VARCHAR(100)');
        $this->addSql('ALTER TABLE compliance_folders ALTER last_name TYPE VARCHAR(100)');
        $this->addSql('ALTER TABLE compliance_folders ALTER email TYPE VARCHAR(255)');
        $this->addSql('ALTER TABLE compliance_folders ALTER address TYPE VARCHAR(500)');
        $this->addSql('ALTER TABLE support_messages ALTER attachment_filename TYPE VARCHAR(255)');
    }
}
