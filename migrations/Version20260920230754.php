<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260920230754 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute pdf_storage_path à suitability_validated_investor_profiles (lot 4 : PDF de synthèse / déclaration d\'adéquation)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE suitability_validated_investor_profiles ADD pdf_storage_path VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE suitability_validated_investor_profiles DROP pdf_storage_path');
    }
}
