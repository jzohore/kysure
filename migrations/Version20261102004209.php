<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261102004209 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Pièces à deux faces : verso déposé à part (CNI).';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE compliance_documents ADD back_storage_path VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE compliance_documents ADD back_mime_type VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE compliance_documents ADD back_size INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE compliance_documents DROP back_storage_path');
        $this->addSql('ALTER TABLE compliance_documents DROP back_mime_type');
        $this->addSql('ALTER TABLE compliance_documents DROP back_size');
    }
}
