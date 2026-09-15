<?php

declare(strict_types=1);

namespace App\Domain\AuditLog\Enum;

/**
 * Énumération centrale de tous les événements traçables de l'application KYSURE.
 * Garantit la conformité et l'irréfutabilité exigées par l'AMF et l'ACPR.
 */
enum AuditEventType: string
{
    // --- UTILISATEUR & SÉCURITÉ ---
    case USER_REGISTERED = 'security.user_registered';
    case USER_MAGIC_LINK_REQUESTED = 'security.magic_link_requested';
    case USER_LOGGED_IN = 'security.user_logged_in';
    case USER_LOGGED_OUT = 'security.user_logged_out';
    case USER_PROFILE_UPDATED = 'security.profile_updated';
    case DEVICE_CREATED = 'security.device_created';
    case SUSPICIOUS_LOGIN_ATTEMPT = 'security.suspicious_login';
    case ADMIN_IMPERSONATION_START = 'security.impersonation_start';
    case ADMIN_IMPERSONATION_EXIT = 'security.impersonation_exit';

    // --- WORKSPACE (CABINET) ---
    case WORKSPACE_CREATED = 'workspace.created';
    case WORKSPACE_UPDATED = 'workspace.updated';
    case WORKSPACE_SUSPENDED = 'workspace.suspended';
    case WORKSPACE_REACTIVATED = 'workspace.reactivated';
    case WORKSPACE_INVITATION_SENT = 'workspace.invitation_sent';
    case WORKSPACE_INVITATION_RESENT = 'workspace.invitation_resent';
    case WORKSPACE_INVITATION_ACCEPTED = 'workspace.invitation_accepted';
    case WORKSPACE_INVITATION_REVOKED = 'workspace.invitation_revoked';
    case WORKSPACE_INVITATION_EXPIRED = 'workspace.invitation_expired';
    case WORKSPACE_MEMBER_ADDED = 'workspace.member_added';
    case WORKSPACE_MEMBER_REVOKED = 'workspace.member_revoked';
    case WORKSPACE_MEMBER_SUSPENDED = 'workspace.member_suspended';
    case WORKSPACE_MEMBER_REACTIVATED = 'workspace.member_reactivated';
    case WORKSPACE_PERMISSIONS_UPDATED = 'workspace.permissions_updated';
    case WORKSPACE_QUOTA_GRANTED = 'workspace.quota_granted';
    case ONBOARDING_COMPLETED = 'workspace.onboarding_completed';

    // --- REGISTRATIONS & VÉRIFICATIONS LÉGALES ---
    case SIRET_CHECK_SUCCESS = 'compliance.siret_check_success';
    case SIRET_CHECK_FAILED = 'compliance.siret_check_failed';
    case ORIAS_CHECK_SUCCESS = 'compliance.orias_check_success';
    case ORIAS_CHECK_FAILED = 'compliance.orias_check_failed';

    // --- DOSSIERS KYC & CONFORMITÉ LCB-FT ---
    case KYC_FOLDER_INITIATED = 'kyc.folder_initiated';
    case KYC_PORTAL_ACCESSED = 'kyc.portal_accessed';
    case KYC_COMPANY_BOUND = 'kyc.company_bound';
    case KYC_FOLDER_SUBMITTED = 'kyc.folder_submitted';
    case KYC_FOLDER_APPROVED = 'kyc.folder_approved';
    case KYC_FOLDER_REJECTED = 'kyc.folder_rejected';
    case KYC_FOLDER_ARCHIVED = 'kyc.folder_archived';
    case KYC_FOLDER_DELETED = 'kyc.folder_deleted';
    case KYC_RELATIONSHIP_ENDED = 'kyc.relationship_ended';
    case KYC_LEGAL_HOLD_PLACED = 'kyc.legal_hold_placed';
    case KYC_LEGAL_HOLD_LIFTED = 'kyc.legal_hold_lifted';

    // --- PORTEFEUILLE CLIENT ---
    case CLIENT_CREATED = 'client.created';
    case CLIENT_ATTACHED_TO_WORKSPACE = 'client.attached_to_workspace';
    case CLIENT_DETACHED_FROM_WORKSPACE = 'client.detached_from_workspace';
    case CLIENT_RELATIONSHIP_ENDED = 'client.relationship_ended';
    case CLIENT_ACCOUNT_DELETED = 'client.account_deleted';

    // --- INTERVENANTS KYC (STAKEHOLDERS / UBO) ---
    case KYC_STAKEHOLDER_ADDED = 'kyc.stakeholder_added';
    case KYC_STAKEHOLDER_REMOVED = 'kyc.stakeholder_removed';

    // --- DOCUMENTS KYC (PIÈCES JUSTIFICATIVES) ---
    case KYC_DOCUMENT_REQUESTED = 'document.requested';
    case KYC_DOCUMENT_UPLOADED = 'document.uploaded';
    case KYC_DOCUMENT_OCR_PROCESSED = 'document.ocr_processed';
    case KYC_DOCUMENT_VALIDATED = 'document.validated';
    case KYC_DOCUMENT_REJECTED = 'document.rejected';
    case KYC_DOCUMENT_EXPIRED = 'document.expired';
    case DOCUMENT_SHARED = 'document.shared';

    // --- CONFORMITÉ DER & SIGNATURE ÉLECTRONIQUE ---
    case DER_GENERATED = 'der.generated';
    /** @deprecated conservé pour l'historique — l'accusé de réception (DER_ACKNOWLEDGED) l'a remplacé. */
    case DER_OPENED = 'der.opened';
    /** @deprecated conservé pour l'historique — signature DocuSeal abandonnée, cf. DER_ACKNOWLEDGED. */
    case DER_SIGNED = 'der.signed';
    case DER_ACKNOWLEDGED = 'der.acknowledged';
    case DER_ACKNOWLEDGEMENT_REVOKED = 'der.acknowledgement_revoked';
    case DER_DECLINED = 'der.declined';
    case SIGNATURE_UPDATED = 'der.signature_updated';

    case ACCEPTED_RECORDING = 'ac.recording';
    case MEETING_REPORT_VALIDATED = 'meeting.report_validated';
    case MEETING_REPORT_REVOKED = 'meeting.report_revoked';

    // --- ANALYSES & SCREENING (SANCTIONS / PPE) ---
    case SCREENING_PERFORMED = 'screening.performed';
    case ADVISORY_REPORT_GENERATED = 'advisory.generated';

    // --- FACTURATION & ABONNEMENT STRIPE ---
    case SUBSCRIPTION_ACTIVATED = 'billing.subscription_activated';
    case SUBSCRIPTION_CANCELED = 'billing.subscription_canceled';
    case SUBSCRIPTION_TRIAL_EXTENDED = 'billing.trial_extended';
    case SUBSCRIPTION_PAUSED = 'billing.subscription_paused';
    case SUBSCRIPTION_RESUMED = 'billing.subscription_resumed';
    case SUBSCRIPTION_RETENTION_OFFER_CLAIMED = 'billing.retention_offer_claimed';
    case SUBSCRIPTION_SEATS_UPDATED = 'billing.subscription_seats_updated';
    case SUBSCRIPTION_CANCELLATION_REVOKED = 'billing.subscription_cancellation_revoked';

    // --- SUPPORT & TICKETS ---
    case SUPPORT_TICKET_CREATED = 'support.ticket_created';
    case SUPPORT_TICKET_RESOLVED = 'support.ticket_resolved';
    case SUPPORT_TICKET_REOPENED = 'support.ticket_reopened';
    case SUPPORT_TICKET_ASSIGNED = 'support.ticket_assigned';

    // --- ÉQUIPE KYSURE (BACK-OFFICE) ---
    case ADMIN_ACCOUNT_CREATED = 'team.admin_account_created';
    case ADMIN_ACCOUNT_PROFILE_UPDATED = 'team.admin_account_profile_updated';
    case ADMIN_ACCOUNT_ROLES_CHANGED = 'team.admin_account_roles_changed';
    case ADMIN_ACCOUNT_SUSPENDED = 'team.admin_account_suspended';
    case ADMIN_ACCOUNT_REACTIVATED = 'team.admin_account_reactivated';
    case ADMIN_ACCOUNT_ARCHIVED = 'team.admin_account_archived';
    case ADMIN_ACCOUNT_DELETED = 'team.admin_account_deleted';

    // --- TÂCHES PLANIFIÉES (CRON) ---
    case CRON_DEFINITION_ENABLED = 'ops.cron_enabled';
    case CRON_DEFINITION_DISABLED = 'ops.cron_disabled';
    case CRON_JOB_TRIGGERED_MANUALLY = 'ops.cron_triggered_manually';
    case CRON_FREQUENCY_CHANGED = 'ops.cron_frequency_changed';

    /**
     * Libellé explicite en français pour l'affichage dans les journaux d'audit.
     */
    public function getLabel(): string
    {
        return match ($this) {
            // Sécurité
            self::USER_REGISTERED => 'Inscription de l\'utilisateur',
            self::USER_MAGIC_LINK_REQUESTED => 'Demande de lien de connexion',
            self::USER_LOGGED_IN => 'Connexion réussie',
            self::USER_LOGGED_OUT => 'Déconnexion',
            self::USER_PROFILE_UPDATED => 'Mise à jour du profil',
            self::DEVICE_CREATED => 'Nouvel appareil enregistré',
            self::SUSPICIOUS_LOGIN_ATTEMPT => 'Tentative de connexion suspecte',
            self::ADMIN_IMPERSONATION_START => 'Connexion support (Impersonation)',
            self::ADMIN_IMPERSONATION_EXIT => 'Fin de connexion support',

            // Équipe KYSURE
            self::ADMIN_ACCOUNT_CREATED => 'Création d\'un compte de l\'équipe KYSURE',
            self::ADMIN_ACCOUNT_PROFILE_UPDATED => 'Modification du profil d\'un membre de l\'équipe',
            self::ADMIN_ACCOUNT_ROLES_CHANGED => 'Changement de rôle d\'un membre de l\'équipe',
            self::ADMIN_ACCOUNT_SUSPENDED => 'Suspension d\'un compte de l\'équipe KYSURE',
            self::ADMIN_ACCOUNT_REACTIVATED => 'Réactivation d\'un compte de l\'équipe KYSURE',
            self::ADMIN_ACCOUNT_ARCHIVED => 'Archivage d\'un compte de l\'équipe KYSURE',
            self::ADMIN_ACCOUNT_DELETED => 'Suppression définitive d\'un compte de l\'équipe KYSURE',

            // Tâches planifiées
            self::CRON_DEFINITION_ENABLED => 'Activation d\'une tâche planifiée',
            self::CRON_DEFINITION_DISABLED => 'Désactivation d\'une tâche planifiée',
            self::CRON_JOB_TRIGGERED_MANUALLY => 'Déclenchement manuel d\'une tâche planifiée',
            self::CRON_FREQUENCY_CHANGED => 'Changement de fréquence d\'une tâche planifiée',

            // Workspace
            self::WORKSPACE_CREATED => 'Création du cabinet',
            self::WORKSPACE_UPDATED => 'Modification de la fiche du cabinet',
            self::WORKSPACE_SUSPENDED => 'Suspension du cabinet',
            self::WORKSPACE_REACTIVATED => 'Réactivation du cabinet',
            self::WORKSPACE_INVITATION_SENT => 'Invitation d\'un collaborateur envoyée',
            self::WORKSPACE_INVITATION_RESENT => 'Invitation de collaborateur renvoyée',
            self::WORKSPACE_INVITATION_ACCEPTED => 'Invitation acceptée — accès accordé',
            self::WORKSPACE_INVITATION_REVOKED => 'Annulation d\'une invitation de collaborateur',
            self::WORKSPACE_INVITATION_EXPIRED => 'Expiration et purge d\'invitations non acceptées',
            self::WORKSPACE_MEMBER_ADDED => 'Ajout d\'un collaborateur',
            self::WORKSPACE_MEMBER_REVOKED => 'Révocation d\'un collaborateur',
            self::WORKSPACE_MEMBER_SUSPENDED => 'Suspension du compte d\'un collaborateur',
            self::WORKSPACE_MEMBER_REACTIVATED => 'Réactivation du compte d\'un collaborateur',
            self::WORKSPACE_PERMISSIONS_UPDATED => 'Modification des droits délégués aux collaborateurs',
            self::WORKSPACE_QUOTA_GRANTED => 'Recharge de quota (dossiers / minutes)',
            self::ONBOARDING_COMPLETED => 'Configuration initiale terminée',

            // Vérifications Légales
            self::SIRET_CHECK_SUCCESS => 'Validation SIRET (INSEE)',
            self::SIRET_CHECK_FAILED => 'Échec de validation SIRET',
            self::ORIAS_CHECK_SUCCESS => 'Validation agrément ORIAS',
            self::ORIAS_CHECK_FAILED => 'Échec de validation ORIAS',

            // KYC
            self::KYC_FOLDER_INITIATED => 'Création du dossier de conformité',
            self::KYC_PORTAL_ACCESSED => 'Ouverture du portail par le client',
            self::KYC_COMPANY_BOUND => 'Identification de la société',
            self::KYC_FOLDER_SUBMITTED => 'Soumission du dossier par le client',
            self::KYC_FOLDER_APPROVED => 'Validation finale du dossier LCB-FT',
            self::KYC_FOLDER_REJECTED => 'Rejet du dossier LCB-FT',
            self::KYC_FOLDER_ARCHIVED => 'Archivage du dossier LCB-FT',
            self::KYC_FOLDER_DELETED => 'Suppression du dossier LCB-FT',
            self::KYC_RELATIONSHIP_ENDED => 'Fin de la relation d\'affaires',
            self::KYC_LEGAL_HOLD_PLACED => 'Pose d\'un verrou de litige',
            self::KYC_LEGAL_HOLD_LIFTED => 'Levée du verrou de litige',

            self::CLIENT_CREATED => 'Création d\'un client',
            self::CLIENT_ATTACHED_TO_WORKSPACE => 'Rattachement d\'un client au cabinet',
            self::CLIENT_DETACHED_FROM_WORKSPACE => 'Retrait d\'un client du cabinet',
            self::CLIENT_RELATIONSHIP_ENDED => 'Clôture de la relation d\'affaires (client)',
            self::CLIENT_ACCOUNT_DELETED => 'Suppression d\'un compte client',

            // Stakeholders
            self::KYC_STAKEHOLDER_ADDED => 'Ajout d\'un bénéficiaire effectif (UBO)',
            self::KYC_STAKEHOLDER_REMOVED => 'Suppression d\'un bénéficiaire effectif',

            // Documents
            self::KYC_DOCUMENT_REQUESTED => 'Nouvelle pièce justificative requise',
            self::KYC_DOCUMENT_UPLOADED => 'Dépôt d\'un document client',
            self::KYC_DOCUMENT_OCR_PROCESSED => 'Analyse OCR automatique (Textract)',
            self::KYC_DOCUMENT_VALIDATED => 'Validation d\'un document',
            self::KYC_DOCUMENT_REJECTED => 'Refus d\'un document',
            self::KYC_DOCUMENT_EXPIRED => 'Expiration d\'un document',
            self::DOCUMENT_SHARED => 'Partage d\'un document sécurisé',

            // DER & Signature
            self::DER_GENERATED => 'Génération du Document d\'Entrée en Relation (DER)',
            self::DER_OPENED => 'Consultation du DER par le client',
            self::DER_SIGNED => 'Signature électronique du DER',
            self::DER_ACKNOWLEDGED => 'Accusé de réception du DER par le client',
            self::DER_ACKNOWLEDGEMENT_REVOKED => 'Révocation de l\'accusé de réception du DER',
            self::DER_DECLINED => 'Refus du DER par le client',
            self::SIGNATURE_UPDATED => 'Mise à jour des paramètres de signature',
            self::ACCEPTED_RECORDING => 'Accord pour enregistrer l\'entretien',
            self::MEETING_REPORT_VALIDATED => 'Validation du rapport d\'entretien IA',
            self::MEETING_REPORT_REVOKED => 'Révocation du rapport d\'entretien IA',

            // Screening
            self::SCREENING_PERFORMED => 'Vérification Listes de Sanctions / PPE',
            self::ADVISORY_REPORT_GENERATED => 'Génération du rapport d\'adéquation',

            // Facturation
            self::SUBSCRIPTION_ACTIVATED => 'Activation de l\'abonnement SaaS',
            self::SUBSCRIPTION_CANCELED => 'Résiliation de l\'abonnement',
            self::SUBSCRIPTION_TRIAL_EXTENDED => 'Prolongation de la période d\'essai',
            self::SUBSCRIPTION_PAUSED => 'Suspension de l\'abonnement',
            self::SUBSCRIPTION_RESUMED => 'Reprise de l\'abonnement',
            self::SUBSCRIPTION_RETENTION_OFFER_CLAIMED => 'Offre de fidélité appliquée',
            self::SUBSCRIPTION_SEATS_UPDATED => 'Ajustement du nombre de sièges facturés',
            self::SUBSCRIPTION_CANCELLATION_REVOKED => 'Annulation de la résiliation programmée',

            // Support
            self::SUPPORT_TICKET_CREATED => 'Ouverture d\'un ticket de support',
            self::SUPPORT_TICKET_RESOLVED => 'Résolution d\'un ticket de support',
            self::SUPPORT_TICKET_REOPENED => 'Réouverture d\'un ticket de support',
            self::SUPPORT_TICKET_ASSIGNED => 'Assignation d\'un ticket de support',
        };
    }

    /**
     * Catégorisation pour le filtrage par onglet dans le back-office KYSURE.
     */
    public function getCategory(): string
    {
        return match ($this) {
            self::USER_REGISTERED,
            self::USER_MAGIC_LINK_REQUESTED,
            self::USER_LOGGED_IN,
            self::USER_LOGGED_OUT,
            self::USER_PROFILE_UPDATED,
            self::DEVICE_CREATED,
            self::SUSPICIOUS_LOGIN_ATTEMPT,
            self::ADMIN_IMPERSONATION_START,
            self::ADMIN_IMPERSONATION_EXIT => 'Sécurité',

            self::WORKSPACE_CREATED,
            self::WORKSPACE_UPDATED,
            self::WORKSPACE_SUSPENDED,
            self::WORKSPACE_REACTIVATED,
            self::WORKSPACE_INVITATION_SENT,
            self::WORKSPACE_INVITATION_RESENT,
            self::WORKSPACE_INVITATION_ACCEPTED,
            self::WORKSPACE_INVITATION_REVOKED,
            self::WORKSPACE_INVITATION_EXPIRED,
            self::WORKSPACE_MEMBER_ADDED,
            self::WORKSPACE_MEMBER_REVOKED,
            self::WORKSPACE_MEMBER_SUSPENDED,
            self::WORKSPACE_MEMBER_REACTIVATED,
            self::WORKSPACE_PERMISSIONS_UPDATED,
            self::WORKSPACE_QUOTA_GRANTED,
            self::ONBOARDING_COMPLETED => 'Cabinet',

            self::SIRET_CHECK_SUCCESS,
            self::SIRET_CHECK_FAILED,
            self::ORIAS_CHECK_SUCCESS,
            self::ORIAS_CHECK_FAILED,
            self::SCREENING_PERFORMED,
            self::ADVISORY_REPORT_GENERATED => 'Agréments & Screening',

            self::KYC_FOLDER_INITIATED,
            self::KYC_PORTAL_ACCESSED,
            self::KYC_COMPANY_BOUND,
            self::KYC_FOLDER_SUBMITTED,
            self::KYC_FOLDER_APPROVED,
            self::KYC_FOLDER_REJECTED,
            self::KYC_STAKEHOLDER_ADDED,
            self::KYC_STAKEHOLDER_REMOVED,
            self::KYC_DOCUMENT_REQUESTED,
            self::KYC_DOCUMENT_UPLOADED,
            self::KYC_DOCUMENT_OCR_PROCESSED,
            self::KYC_DOCUMENT_VALIDATED,
            self::KYC_DOCUMENT_REJECTED,
            self::KYC_DOCUMENT_EXPIRED,
            self::KYC_FOLDER_ARCHIVED,
            self::KYC_FOLDER_DELETED,
            self::KYC_RELATIONSHIP_ENDED,
            self::KYC_LEGAL_HOLD_PLACED,
            self::KYC_LEGAL_HOLD_LIFTED,
            self::DOCUMENT_SHARED => 'Dossiers LCB-FT',

            self::CLIENT_CREATED,
            self::CLIENT_ATTACHED_TO_WORKSPACE,
            self::CLIENT_DETACHED_FROM_WORKSPACE,
            self::CLIENT_RELATIONSHIP_ENDED,
            self::CLIENT_ACCOUNT_DELETED => 'Clients',

            self::DER_GENERATED,
            self::DER_OPENED,
            self::DER_SIGNED,
            self::DER_ACKNOWLEDGED,
            self::DER_ACKNOWLEDGEMENT_REVOKED,
            self::DER_DECLINED,
            self::ACCEPTED_RECORDING,
            self::MEETING_REPORT_VALIDATED,
            self::MEETING_REPORT_REVOKED,
            self::SIGNATURE_UPDATED => 'DER & Signature',

            self::SUBSCRIPTION_ACTIVATED,
            self::SUBSCRIPTION_CANCELED,
            self::SUBSCRIPTION_TRIAL_EXTENDED,
            self::SUBSCRIPTION_PAUSED,
            self::SUBSCRIPTION_RESUMED,
            self::SUBSCRIPTION_RETENTION_OFFER_CLAIMED,
            self::SUBSCRIPTION_SEATS_UPDATED,
            self::SUBSCRIPTION_CANCELLATION_REVOKED => 'Abonnement',

            self::SUPPORT_TICKET_CREATED,
            self::SUPPORT_TICKET_RESOLVED,
            self::SUPPORT_TICKET_REOPENED,
            self::SUPPORT_TICKET_ASSIGNED => 'Support & Tickets',

            self::ADMIN_ACCOUNT_CREATED,
            self::ADMIN_ACCOUNT_PROFILE_UPDATED,
            self::ADMIN_ACCOUNT_ROLES_CHANGED,
            self::ADMIN_ACCOUNT_SUSPENDED,
            self::ADMIN_ACCOUNT_REACTIVATED,
            self::ADMIN_ACCOUNT_ARCHIVED,
            self::ADMIN_ACCOUNT_DELETED => 'Équipe KYSURE',

            self::CRON_DEFINITION_ENABLED,
            self::CRON_DEFINITION_DISABLED,
            self::CRON_JOB_TRIGGERED_MANUALLY,
            self::CRON_FREQUENCY_CHANGED => 'Tâches planifiées',
        };
    }

    /**
     * Style de badge Tailwind CSS pré-calculé pour une scannabilité immédiate dans Twig.
     */
    public function getBadgeColor(): string
    {
        return match ($this) {
            self::KYC_FOLDER_APPROVED,
            self::DER_SIGNED,
            self::DER_ACKNOWLEDGED,
            self::MEETING_REPORT_VALIDATED,
            self::ORIAS_CHECK_SUCCESS,
            self::SIRET_CHECK_SUCCESS,
            self::WORKSPACE_INVITATION_ACCEPTED,
            self::WORKSPACE_REACTIVATED,
            self::SUBSCRIPTION_ACTIVATED => 'bg-emerald-50 text-emerald-700 border-emerald-200',

            self::SUSPICIOUS_LOGIN_ATTEMPT,
            self::WORKSPACE_SUSPENDED,
            self::KYC_FOLDER_REJECTED,
            self::KYC_DOCUMENT_REJECTED,
            self::ORIAS_CHECK_FAILED,
            self::SIRET_CHECK_FAILED,
            self::MEETING_REPORT_REVOKED,
            self::DER_ACKNOWLEDGEMENT_REVOKED,
            self::DER_DECLINED,
            self::WORKSPACE_INVITATION_REVOKED,
            self::SUBSCRIPTION_CANCELED => 'bg-rose-50 text-rose-700 border-rose-200',

            self::ADMIN_IMPERSONATION_START,
            self::ADMIN_IMPERSONATION_EXIT,
            self::SUBSCRIPTION_TRIAL_EXTENDED => 'bg-amber-50 text-amber-700 border-amber-200',

            self::KYC_DOCUMENT_OCR_PROCESSED,
            self::SCREENING_PERFORMED,
            self::ADVISORY_REPORT_GENERATED => 'bg-indigo-50 text-indigo-700 border-indigo-200',

            default => 'bg-slate-100 text-slate-700 border-slate-200',
        };
    }

    /**
     * Détermine si l'événement nécessite un surlignage critique ou une alerte admin.
     */
    public function isCritical(): bool
    {
        return match ($this) {
            self::SUSPICIOUS_LOGIN_ATTEMPT,
            self::WORKSPACE_SUSPENDED,
            self::ORIAS_CHECK_FAILED,
            self::SIRET_CHECK_FAILED,
            self::KYC_FOLDER_REJECTED,
            self::MEETING_REPORT_REVOKED,
            self::DER_ACKNOWLEDGEMENT_REVOKED,
            self::DER_DECLINED,
            self::KYC_LEGAL_HOLD_PLACED,
            self::CLIENT_ACCOUNT_DELETED,
            self::ADMIN_IMPERSONATION_START,
            self::ADMIN_ACCOUNT_CREATED,
            self::ADMIN_ACCOUNT_ROLES_CHANGED,
            self::ADMIN_ACCOUNT_ARCHIVED,
            self::ADMIN_ACCOUNT_DELETED => true,
            default => false,
        };
    }

    /**
     * Détermine si l'événement doit être affiché dans le journal du Cabinet utilisateur,
     * ou s'il s'agit d'une métrique interne réservée aux Administrateurs KYSURE.
     */
    public function isVisibleToWorkspace(): bool
    {
        return match ($this) {
            self::USER_MAGIC_LINK_REQUESTED,
            self::USER_LOGGED_OUT,
            self::KYC_DOCUMENT_OCR_PROCESSED,
            self::ONBOARDING_COMPLETED,
            self::CRON_DEFINITION_ENABLED,
            self::CRON_DEFINITION_DISABLED,
            self::CRON_JOB_TRIGGERED_MANUALLY,
            self::CRON_FREQUENCY_CHANGED,
            // Assigner un ticket à un opérateur KYSURE est un détail de routage interne,
            // pas une information utile au cabinet (contrairement à la création/résolution).
            self::SUPPORT_TICKET_ASSIGNED => false,
            // La connexion support DOIT être visible du cabinet : c'est ce qui lui
            // permet de répondre à « qui a ouvert ce dossier, quand, pourquoi ».
            default => true,
        };
    }
}
