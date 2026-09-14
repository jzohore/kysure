<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Socle de reprise (audit ticket #18 bis) : recrée les tables du schéma initial
 * qui n'ont jamais été créées par une migration présente dans le dépôt.
 *
 * Avant le 2026-05-20, les tables étaient créées directement en base (schema:update
 * / schema:create) sans jamais passer par doctrine-migrations. À partir de cette
 * date, 27 migrations ont bien été exécutées et tracées dans doctrine_migration_versions,
 * mais leurs fichiers PHP ont été perdus (jamais commités dans git, confirmé par
 * `git log --all --diff-filter=A` sur chaque version) avant d'être supprimés ou
 * jamais ajoutés. Leur contenu exact est donc irrécupérable.
 *
 * Cette migration ne recrée PAS le contenu historique de ces 27 versions : elle
 * recrée uniquement, dans leur forme *antérieure* aux migrations aujourd'hui
 * présentes dans ce dossier, les tables que plus aucun fichier ne crée. Toute
 * colonne/contrainte/index ajouté ensuite par une migration existante (ex.
 * workspaces.email, compliance_folders.client_id, etc.) est volontairement
 * exclue d'ici : ces migrations restent responsables de les ajouter, dans l'ordre.
 *
 * Sur une base qui a déjà ce schéma (dev/staging/prod actuels), cette version doit
 * être enregistrée sans exécution : `doctrine:migrations:version --add
 * "DoctrineMigrations\\Version20260520000000"`. Sur une base neuve (CI, nouvel
 * environnement), elle s'exécute normalement et permet à `migrate` de repartir de zéro.
 */
final class Version20260520000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Socle de reprise : tables du schéma initial jamais créées par une migration existante';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE workspaces (id UUID NOT NULL, name VARCHAR(255) NOT NULL, slug_id VARCHAR(255) NOT NULL, siret VARCHAR(14) DEFAULT NULL, legal_name VARCHAR(255) DEFAULT NULL, address TEXT DEFAULT NULL, logo_filename VARCHAR(255) DEFAULT NULL, industry VARCHAR(50) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, is_active BOOLEAN DEFAULT false, type VARCHAR(255) DEFAULT NULL, etat_administratif VARCHAR(14) DEFAULT NULL, is_siret_valid BOOLEAN DEFAULT true, verify_siret_last_attempted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, siren VARCHAR(14) DEFAULT NULL, suspension_reason VARCHAR(255) DEFAULT NULL, suspended_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, public_token VARCHAR(64) DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_7FE8F3CB26E94372 ON workspaces (siret)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_7FE8F3CB311966CE ON workspaces (slug_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_7FE8F3CBAE981E3B ON workspaces (public_token)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_7FE8F3CBDB8BBA08 ON workspaces (siren)');

        $this->addSql('CREATE TABLE "users" (id UUID NOT NULL, email VARCHAR(180) NOT NULL, slug_id VARCHAR(255) NOT NULL, roles JSON NOT NULL, is_verified BOOLEAN DEFAULT false NOT NULL, is_owner BOOLEAN DEFAULT false NOT NULL, is_actif BOOLEAN DEFAULT false NOT NULL, first_name VARCHAR(100) DEFAULT NULL NOT NULL, last_name VARCHAR(100) DEFAULT NULL NOT NULL, magic_link_token VARCHAR(255) DEFAULT NULL, magic_link_token_expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, onboarding_reminder_sent_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, onboarding_status VARCHAR(50) DEFAULT NULL, profile_job_title VARCHAR(100) DEFAULT NULL, profile_phone_number VARCHAR(20) DEFAULT NULL, profile_dismiss_onboarding BOOLEAN DEFAULT false NOT NULL, profile_stripe_customer_id VARCHAR(255) DEFAULT NULL, profile_lang VARCHAR(5) DEFAULT NULL, workspace_id UUID DEFAULT NULL, google_authenticator_secret VARCHAR(150) DEFAULT NULL, is_totp_verified BOOLEAN DEFAULT false NOT NULL, trusted_version INT DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_1483A5E982D40A1F ON "users" (workspace_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_1483A5E9311966CE ON "users" (slug_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_1483A5E9E7927C74 ON "users" (email)');
        $this->addSql('ALTER TABLE "users" ADD CONSTRAINT FK_1483A5E982D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id)');

        $this->addSql('CREATE TABLE workspace_members (id UUID NOT NULL, role VARCHAR(50) NOT NULL, joined_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id UUID NOT NULL, workspace_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_9D9D39F482D40A1F ON workspace_members (workspace_id)');
        $this->addSql('CREATE INDEX IDX_9D9D39F4A76ED395 ON workspace_members (user_id)');
        $this->addSql('CREATE UNIQUE INDEX idx_unique_user_workspace ON workspace_members (user_id, workspace_id)');
        $this->addSql('ALTER TABLE workspace_members ADD CONSTRAINT FK_9D9D39F482D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE workspace_members ADD CONSTRAINT FK_9D9D39F4A76ED395 FOREIGN KEY (user_id) REFERENCES "users" (id) ON DELETE CASCADE NOT DEFERRABLE');

        $this->addSql('CREATE TABLE workspaces_invitations (id UUID NOT NULL, email VARCHAR(180) NOT NULL, first_name VARCHAR(100) DEFAULT NULL, last_name VARCHAR(100) DEFAULT NULL, slug_id VARCHAR(255) NOT NULL, invitation_status VARCHAR(50) DEFAULT NULL, invited_role VARCHAR(50) DEFAULT NULL, magic_link_token VARCHAR(255) DEFAULT NULL, magic_link_token_expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, workspace_id UUID DEFAULT NULL, owner_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_47a29ea27e3c61f9 ON workspaces_invitations (owner_id)');
        $this->addSql('CREATE INDEX idx_47a29ea282d40a1f ON workspaces_invitations (workspace_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_47A29EA2311966CE ON workspaces_invitations (slug_id)');
        $this->addSql('ALTER TABLE workspaces_invitations ADD CONSTRAINT fk_47a29ea282d40a1f FOREIGN KEY (workspace_id) REFERENCES workspaces (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE workspaces_invitations ADD CONSTRAINT fk_47a29ea27e3c61f9 FOREIGN KEY (owner_id) REFERENCES "users" (id) NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('CREATE TABLE subscriptions (id UUID NOT NULL, status VARCHAR(255) NOT NULL, plan_reference VARCHAR(255) NOT NULL, stripe_subscription_id VARCHAR(255) DEFAULT NULL, stripe_price_id VARCHAR(255) NOT NULL, current_period_start TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, current_period_end TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, cancel_at_period_end BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, update_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, trial_ends_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, reason VARCHAR(255) DEFAULT NULL, workspace_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_4778A0182D40A1F ON subscriptions (workspace_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_4778A01B5DBB761 ON subscriptions (stripe_subscription_id)');
        $this->addSql('ALTER TABLE subscriptions ADD CONSTRAINT FK_4778A0182D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id)');

        $this->addSql('CREATE TABLE regulatory_profiles (id UUID NOT NULL, orias_number VARCHAR(20) DEFAULT NULL, professional_association VARCHAR(100) DEFAULT NULL, rc_pro_insurer VARCHAR(255) DEFAULT NULL, rc_pro_policy_number VARCHAR(100) DEFAULT NULL, is_independent BOOLEAN DEFAULT true NOT NULL, partners JSON NOT NULL, workspace_id UUID NOT NULL, logo_storage_path VARCHAR(255) DEFAULT NULL, filename VARCHAR(255) DEFAULT NULL, mime_type VARCHAR(255) DEFAULT NULL, size INT DEFAULT NULL, signature_base64 TEXT DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_FA7274F882D40A1F ON regulatory_profiles (workspace_id)');
        $this->addSql('ALTER TABLE regulatory_profiles ADD CONSTRAINT FK_FA7274F882D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE NOT DEFERRABLE');

        $this->addSql('CREATE TABLE kyc_folders (id UUID NOT NULL, reference VARCHAR(20) NOT NULL, slug_id VARCHAR(255) NOT NULL, contact_first_name VARCHAR(100) NOT NULL, contact_last_name VARCHAR(100) NOT NULL, contact_email VARCHAR(255) NOT NULL, company_name VARCHAR(255) DEFAULT NULL, siret VARCHAR(14) DEFAULT NULL, siren VARCHAR(9) DEFAULT NULL, status_administratif VARCHAR(3) DEFAULT NULL, address VARCHAR(255) DEFAULT NULL, status VARCHAR(255) NOT NULL, legal_category VARCHAR(255) DEFAULT NULL, history JSON NOT NULL, share_token VARCHAR(255) NOT NULL, share_token_expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, is_certified BOOLEAN DEFAULT NULL, submitted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, workspace_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_989D5EB882D40A1F ON kyc_folders (workspace_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_989D5EB8311966CE ON kyc_folders (slug_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_989D5EB8AEA34913 ON kyc_folders (reference)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_989D5EB8D6594DD6 ON kyc_folders (share_token)');
        $this->addSql('ALTER TABLE kyc_folders ADD CONSTRAINT FK_989D5EB882D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id)');

        $this->addSql('CREATE TABLE stake_holders (id UUID NOT NULL, slug_id VARCHAR(255) NOT NULL, first_name VARCHAR(100) NOT NULL, last_name VARCHAR(100) NOT NULL, role VARCHAR(255) NOT NULL, ownership_percentage DOUBLE PRECISION DEFAULT NULL, folder_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_3ECD0891162CB942 ON stake_holders (folder_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_3ECD0891311966CE ON stake_holders (slug_id)');
        $this->addSql('ALTER TABLE stake_holders ADD CONSTRAINT FK_3ECD0891162CB942 FOREIGN KEY (folder_id) REFERENCES kyc_folders (id)');

        $this->addSql('CREATE TABLE kyc_documents (id UUID NOT NULL, slug_id VARCHAR(255) NOT NULL, type VARCHAR(255) NOT NULL, status VARCHAR(255) NOT NULL, storage_path VARCHAR(255) DEFAULT NULL, rejection_reason TEXT DEFAULT NULL, expires_at DATE DEFAULT NULL, ocr_data JSON DEFAULT NULL, folder_id UUID NOT NULL, stakeholder_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_9A3DD3C0162CB942 ON kyc_documents (folder_id)');
        $this->addSql('CREATE INDEX IDX_9A3DD3C0F2D3711A ON kyc_documents (stakeholder_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_9A3DD3C0311966CE ON kyc_documents (slug_id)');
        $this->addSql('ALTER TABLE kyc_documents ADD CONSTRAINT FK_9A3DD3C0162CB942 FOREIGN KEY (folder_id) REFERENCES kyc_folders (id)');
        $this->addSql('ALTER TABLE kyc_documents ADD CONSTRAINT FK_9A3DD3C0F2D3711A FOREIGN KEY (stakeholder_id) REFERENCES stake_holders (id) ON DELETE CASCADE NOT DEFERRABLE');

        $this->addSql('CREATE TABLE legal_update_demands (id UUID NOT NULL, requested_siret VARCHAR(14) NOT NULL, requested_siren VARCHAR(9) NOT NULL, requested_name VARCHAR(255) NOT NULL, kbis_document_path VARCHAR(255) DEFAULT NULL, identity_document_path VARCHAR(255) DEFAULT NULL, status VARCHAR(255) NOT NULL, rejection_reason TEXT DEFAULT NULL, submitted_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, workspace_id UUID NOT NULL, slug_id VARCHAR(255) NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_547F64AE82D40A1F ON legal_update_demands (workspace_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_547F64AE311966CE ON legal_update_demands (slug_id)');
        $this->addSql('ALTER TABLE legal_update_demands ADD CONSTRAINT FK_547F64AE82D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE NOT DEFERRABLE');

        $this->addSql('CREATE TABLE audit_logs (id UUID NOT NULL, event_name VARCHAR(180) NOT NULL, slug_id VARCHAR(255) NOT NULL, payload JSON NOT NULL, occurred_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, workspace_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_D62F285841E832AD ON audit_logs (event_name)');
        $this->addSql('CREATE INDEX IDX_D62F285882D40A1F ON audit_logs (workspace_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_D62F2858311966CE ON audit_logs (slug_id)');
        $this->addSql('ALTER TABLE audit_logs ADD CONSTRAINT FK_D62F285882D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id)');

        $this->addSql('CREATE TABLE compliance_folders (id UUID NOT NULL, reference VARCHAR(20) NOT NULL, slug_id VARCHAR(255) NOT NULL, status VARCHAR(255) NOT NULL, history JSON NOT NULL, share_token VARCHAR(255) DEFAULT NULL, risk_level VARCHAR(255) DEFAULT NULL, diligence_level VARCHAR(255) NOT NULL, next_review_date TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, submitted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, is_certified BOOLEAN NOT NULL, metadata JSON DEFAULT NULL, is_confidential BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, workspace_id UUID NOT NULL, assigned_reviewer_id UUID DEFAULT NULL, dtype VARCHAR(255) NOT NULL, first_name VARCHAR(100) DEFAULT NULL, last_name VARCHAR(100) DEFAULT NULL, email VARCHAR(255) DEFAULT NULL, company_name VARCHAR(255) DEFAULT NULL, siret VARCHAR(14) DEFAULT NULL, legal_category VARCHAR(255) DEFAULT NULL, creation_method VARCHAR(255) NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_B4046EBE576DA6DF ON compliance_folders (assigned_reviewer_id)');
        $this->addSql('CREATE INDEX IDX_B4046EBE82D40A1F ON compliance_folders (workspace_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_B4046EBE26E94372 ON compliance_folders (siret)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_B4046EBE311966CE ON compliance_folders (slug_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_B4046EBEAEA34913 ON compliance_folders (reference)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_B4046EBED6594DD6 ON compliance_folders (share_token)');
        $this->addSql('ALTER TABLE compliance_folders ADD CONSTRAINT FK_B4046EBE576DA6DF FOREIGN KEY (assigned_reviewer_id) REFERENCES "users" (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE compliance_folders ADD CONSTRAINT FK_B4046EBE82D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id)');

        $this->addSql('CREATE TABLE compliance_folder_restricted_users (compliance_folder_id UUID NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (compliance_folder_id, user_id))');
        $this->addSql('CREATE INDEX IDX_72EC667C5E22A3B9 ON compliance_folder_restricted_users (compliance_folder_id)');
        $this->addSql('CREATE INDEX IDX_72EC667CA76ED395 ON compliance_folder_restricted_users (user_id)');
        $this->addSql('ALTER TABLE compliance_folder_restricted_users ADD CONSTRAINT FK_72EC667C5E22A3B9 FOREIGN KEY (compliance_folder_id) REFERENCES compliance_folders (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE compliance_folder_restricted_users ADD CONSTRAINT FK_72EC667CA76ED395 FOREIGN KEY (user_id) REFERENCES "users" (id) ON DELETE CASCADE NOT DEFERRABLE');

        $this->addSql('CREATE TABLE compliance_documents (id UUID NOT NULL, slug_id VARCHAR(255) NOT NULL, type VARCHAR(255) NOT NULL, status VARCHAR(255) NOT NULL, storage_path VARCHAR(255) DEFAULT NULL, rejection_reason TEXT DEFAULT NULL, expires_at DATE DEFAULT NULL, ocr_data JSON DEFAULT NULL, custom_label VARCHAR(255) DEFAULT NULL, is_mandatory BOOLEAN NOT NULL, uploaded_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, folder_id UUID NOT NULL, stakeholder_id UUID DEFAULT NULL, is_ask_to_client BOOLEAN DEFAULT false NOT NULL, filename VARCHAR(255) DEFAULT NULL, mime_type VARCHAR(255) DEFAULT NULL, size INT DEFAULT NULL, docu_seal_submission_id INT DEFAULT NULL, docu_seal_document_url VARCHAR(255) DEFAULT NULL, docu_seal_signed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, docu_seal_audit_log_url VARCHAR(255) DEFAULT NULL, docu_seal_rejected_reason VARCHAR(255) DEFAULT NULL, docu_seal_signature_url VARCHAR(255) DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_EABE6873162CB942 ON compliance_documents (folder_id)');
        $this->addSql('CREATE INDEX IDX_EABE6873F2D3711A ON compliance_documents (stakeholder_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_EABE6873311966CE ON compliance_documents (slug_id)');
        $this->addSql('ALTER TABLE compliance_documents ADD CONSTRAINT FK_EABE6873162CB942 FOREIGN KEY (folder_id) REFERENCES compliance_folders (id)');
        $this->addSql('ALTER TABLE compliance_documents ADD CONSTRAINT FK_EABE6873F2D3711A FOREIGN KEY (stakeholder_id) REFERENCES stake_holders (id) ON DELETE CASCADE NOT DEFERRABLE');

        $this->addSql('CREATE TABLE screening_audits (id UUID NOT NULL, slug_id VARCHAR(255) NOT NULL, query VARCHAR(255) NOT NULL, results JSON NOT NULL, total_matches INT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, status VARCHAR(255) NOT NULL, pdf_path VARCHAR(255) DEFAULT NULL, workspace_id UUID NOT NULL, owner_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_D6BF21187E3C61F9 ON screening_audits (owner_id)');
        $this->addSql('CREATE INDEX IDX_D6BF211882D40A1F ON screening_audits (workspace_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_D6BF2118311966CE ON screening_audits (slug_id)');
        $this->addSql('ALTER TABLE screening_audits ADD CONSTRAINT FK_D6BF21187E3C61F9 FOREIGN KEY (owner_id) REFERENCES "users" (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE screening_audits ADD CONSTRAINT FK_D6BF211882D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE NOT DEFERRABLE');

        $this->addSql('CREATE TABLE user_devices (id UUID NOT NULL, client_info JSON DEFAULT NULL, client_os JSON DEFAULT NULL, client_device_name VARCHAR(255) DEFAULT NULL, client_brand_name VARCHAR(255) DEFAULT NULL, client_is_browser BOOLEAN DEFAULT NULL, client_is_smartphone BOOLEAN DEFAULT NULL, address_ip VARCHAR(45) DEFAULT NULL, session_id VARCHAR(255) DEFAULT NULL, country_iso_code VARCHAR(2) DEFAULT NULL, city_name VARCHAR(180) DEFAULT NULL, postal_code VARCHAR(50) DEFAULT NULL, latitude DOUBLE PRECISION DEFAULT NULL, longitude DOUBLE PRECISION DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, owner_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_490A50907E3C61F9 ON user_devices (owner_id)');
        $this->addSql('ALTER TABLE user_devices ADD CONSTRAINT FK_490A50907E3C61F9 FOREIGN KEY (owner_id) REFERENCES "users" (id) ON DELETE CASCADE NOT DEFERRABLE');

        // Stub minimal : "products" et "wallet_transactions" (système de crédits, retiré).
        // Leur structure d'origine est perdue (une des 27 migrations disparues les a créées) ;
        // Version20260909140000 (disponible) les DROP en toute sécurité juste après. On ne recrée
        // ici que le strict nécessaire pour que ce DROP réussisse sur une base neuve.
        $this->addSql('CREATE TABLE wallet_transactions (id UUID NOT NULL, workspace_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE TABLE products (id UUID NOT NULL, PRIMARY KEY (id))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE compliance_documents DROP CONSTRAINT FK_EABE6873162CB942');
        $this->addSql('ALTER TABLE compliance_documents DROP CONSTRAINT FK_EABE6873F2D3711A');
        $this->addSql('ALTER TABLE compliance_folder_restricted_users DROP CONSTRAINT FK_72EC667C5E22A3B9');
        $this->addSql('ALTER TABLE compliance_folder_restricted_users DROP CONSTRAINT FK_72EC667CA76ED395');
        $this->addSql('ALTER TABLE kyc_documents DROP CONSTRAINT FK_9A3DD3C0162CB942');
        $this->addSql('ALTER TABLE kyc_documents DROP CONSTRAINT FK_9A3DD3C0F2D3711A');
        $this->addSql('ALTER TABLE stake_holders DROP CONSTRAINT FK_3ECD0891162CB942');
        $this->addSql('ALTER TABLE screening_audits DROP CONSTRAINT FK_D6BF21187E3C61F9');
        $this->addSql('ALTER TABLE screening_audits DROP CONSTRAINT FK_D6BF211882D40A1F');
        $this->addSql('ALTER TABLE user_devices DROP CONSTRAINT FK_490A50907E3C61F9');
        $this->addSql('ALTER TABLE compliance_folders DROP CONSTRAINT FK_B4046EBE576DA6DF');
        $this->addSql('ALTER TABLE compliance_folders DROP CONSTRAINT FK_B4046EBE82D40A1F');
        $this->addSql('ALTER TABLE audit_logs DROP CONSTRAINT FK_D62F285882D40A1F');
        $this->addSql('ALTER TABLE legal_update_demands DROP CONSTRAINT FK_547F64AE82D40A1F');
        $this->addSql('ALTER TABLE regulatory_profiles DROP CONSTRAINT FK_FA7274F882D40A1F');
        $this->addSql('ALTER TABLE subscriptions DROP CONSTRAINT FK_4778A0182D40A1F');
        $this->addSql('ALTER TABLE kyc_folders DROP CONSTRAINT FK_989D5EB882D40A1F');
        $this->addSql('ALTER TABLE workspaces_invitations DROP CONSTRAINT fk_47a29ea282d40a1f');
        $this->addSql('ALTER TABLE workspaces_invitations DROP CONSTRAINT fk_47a29ea27e3c61f9');
        $this->addSql('ALTER TABLE workspace_members DROP CONSTRAINT FK_9D9D39F482D40A1F');
        $this->addSql('ALTER TABLE workspace_members DROP CONSTRAINT FK_9D9D39F4A76ED395');
        $this->addSql('ALTER TABLE "users" DROP CONSTRAINT FK_1483A5E982D40A1F');
        $this->addSql('DROP TABLE compliance_documents');
        $this->addSql('DROP TABLE compliance_folder_restricted_users');
        $this->addSql('DROP TABLE kyc_documents');
        $this->addSql('DROP TABLE stake_holders');
        $this->addSql('DROP TABLE screening_audits');
        $this->addSql('DROP TABLE user_devices');
        $this->addSql('DROP TABLE compliance_folders');
        $this->addSql('DROP TABLE audit_logs');
        $this->addSql('DROP TABLE legal_update_demands');
        $this->addSql('DROP TABLE regulatory_profiles');
        $this->addSql('DROP TABLE subscriptions');
        $this->addSql('DROP TABLE kyc_folders');
        $this->addSql('DROP TABLE workspaces_invitations');
        $this->addSql('DROP TABLE workspace_members');
        $this->addSql('DROP TABLE "users"');
        $this->addSql('DROP TABLE workspaces');
        $this->addSql('DROP TABLE wallet_transactions');
        $this->addSql('DROP TABLE products');
    }
}
