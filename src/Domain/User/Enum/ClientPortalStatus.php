<?php

declare(strict_types=1);

namespace App\Domain\User\Enum;

use App\Domain\Compliance\Enum\ComplianceFolderStatus;

enum ClientPortalStatus: string
{
    case UP_TO_DATE = 'up_to_date';
    case ACTION_REQUIRED = 'action_required';
    case UNDER_REVIEW = 'under_review';

    /**
     * Statut vu par le client : source unique pour le tableau de bord, « Mes dossiers » et le détail.
     *
     * Le statut du dossier ne bascule en analyse que lorsque le client le soumet : tant qu'il
     * ne l'a pas fait, un client qui a tout transmis verrait « pièces requises (0) ». Plus aucune
     * pièce à fournir ni à corriger ($pendingDocuments inclut les refus) = il n'a plus rien à
     * faire. AWAITING_CLIENT est exclu : il peut attendre autre chose que des pièces.
     */
    public static function resolve(ComplianceFolderStatus $folderStatus, int $pendingDocuments): self
    {
        $status = self::fromFolderStatus($folderStatus);

        if (self::ACTION_REQUIRED === $status
            && 0 === $pendingDocuments
            && in_array($folderStatus, [ComplianceFolderStatus::PENDING_DOCS, ComplianceFolderStatus::NEEDS_CORRECTION], true)) {
            return self::UNDER_REVIEW;
        }

        return $status;
    }

    public static function fromFolderStatus(ComplianceFolderStatus $folderStatus): self
    {
        return match ($folderStatus) {
            // Le client doit uploader des pièces justificatives
            ComplianceFolderStatus::AWAITING_CLIENT,
            ComplianceFolderStatus::PENDING_DOCS,
            ComplianceFolderStatus::NEEDS_CORRECTION => self::ACTION_REQUIRED,

            // Le cabinet analyse le KYC
            // Le Graal de la conformité
            ComplianceFolderStatus::APPROVED => self::UP_TO_DATE,

            default => self::UNDER_REVIEW,
        };
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::UP_TO_DATE => 'Dossier complet et validé',
            // Générique : le client peut avoir à déposer des pièces, soumettre son dossier ou donner un accord.
            self::ACTION_REQUIRED => 'Action requise',
            self::UNDER_REVIEW => 'En cours d\'analyse par le cabinet',
        };
    }

    /**
     * Classes Tailwind **littérales** du badge de statut. Ne jamais reconstruire
     * ces classes par interpolation dans un template : le scanner Tailwind ne les
     * verrait pas et le badge sortirait sans fond en prod.
     */
    public function badgeClasses(): string
    {
        return match ($this) {
            self::UP_TO_DATE => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
            self::ACTION_REQUIRED => 'bg-amber-50 text-amber-700 ring-amber-600/20',
            self::UNDER_REVIEW => 'bg-slate-100 text-slate-700 ring-slate-600/20',
        };
    }

    /** Classe de bordure d'accent (carte dossier). Littérale — cf. {@see self::badgeClasses()}. */
    public function accentBorderClass(): string
    {
        return match ($this) {
            self::UP_TO_DATE => 'border-l-emerald-500',
            self::ACTION_REQUIRED => 'border-l-amber-500',
            self::UNDER_REVIEW => 'border-l-slate-300',
        };
    }

    /** Classes de la pastille d'icône (carte dossier). Littérales — cf. {@see self::badgeClasses()}. */
    public function iconWrapClasses(): string
    {
        return match ($this) {
            self::UP_TO_DATE => 'bg-emerald-50 text-emerald-600 ring-emerald-500/20',
            self::ACTION_REQUIRED => 'bg-amber-50 text-amber-600 ring-amber-500/20',
            self::UNDER_REVIEW => 'bg-slate-50 text-slate-500 ring-slate-500/20',
        };
    }
}
