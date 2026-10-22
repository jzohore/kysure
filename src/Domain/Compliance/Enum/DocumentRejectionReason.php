<?php

declare(strict_types=1);

namespace App\Domain\Compliance\Enum;

/**
 * Motif de refus d'une pièce par le conseiller. Le libellé est LU PAR LE CLIENT (portail, e-mail de demande de
 * corrections) : ton neutre, actionnable, sans jargon.
 */
enum DocumentRejectionReason: string
{
    case ILLEGIBLE = 'illegible';
    case INCOMPLETE = 'incomplete';
    case EXPIRED = 'expired';
    case TOO_OLD = 'too_old';
    case NAME_MISMATCH = 'name_mismatch';
    case WRONG_DOCUMENT = 'wrong_document';
    case OTHER = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::ILLEGIBLE => 'Le document est illisible ou flou.',
            self::INCOMPLETE => 'Le document est incomplet (recto/verso ou page manquante).',
            self::EXPIRED => 'La pièce d\'identité est expirée.',
            self::TOO_OLD => 'Le justificatif date de plus de 3 mois.',
            self::NAME_MISMATCH => 'Le nom figurant sur le document ne correspond pas à celui du dossier.',
            self::WRONG_DOCUMENT => 'Ce document ne correspond pas à la pièce demandée.',
            self::OTHER => 'Autre motif',
        };
    }

    /** Une précision écrite est indispensable quand le motif ne se suffit pas à lui-même. */
    public function requiresComment(): bool
    {
        return self::OTHER === $this;
    }
}
