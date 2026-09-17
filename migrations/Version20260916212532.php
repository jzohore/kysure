<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260916212532 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Scope la contrainte unique de version du profil investisseur validé par cabinet (client_id, workspace_id, version) au lieu de (client_id, version) — un client peut être suivi par plusieurs cabinets, chacun avec son propre historique de validation.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX uniq_client_profile_version');
        $this->addSql('CREATE UNIQUE INDEX uniq_client_workspace_profile_version ON suitability_validated_investor_profiles (client_id, workspace_id, version)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX uniq_client_workspace_profile_version');
        $this->addSql('CREATE UNIQUE INDEX uniq_client_profile_version ON suitability_validated_investor_profiles (client_id, version)');
    }
}
