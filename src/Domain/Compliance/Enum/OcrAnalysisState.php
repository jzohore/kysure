<?php

declare(strict_types=1);

namespace App\Domain\Compliance\Enum;

/**
 * État de l'analyse automatique d'une pièce, tel que le CGP doit le percevoir.
 * Purement informatif : l'analyse ne décide jamais (cf. {@see \App\Domain\Compliance\Entity\ComplianceDocument}).
 */
enum OcrAnalysisState: string
{
    /** Pièce non reçue, ou type sans objet (DER). */
    case NOT_APPLICABLE = 'not_applicable';

    /** Pièce reçue d'un type sans analyse automatique : le CGP vérifie à la main. */
    case MANUAL_CHECK = 'manual_check';

    /** Pièce reçue, analyse lancée ou en file d'attente. */
    case IN_PROGRESS = 'in_progress';

    /** Analyse terminée (données lues et/ou points de vigilance). */
    case COMPLETED = 'completed';

    /** L'analyse n'a pas pu aboutir (service indisponible, retries épuisés) : vérification manuelle. */
    case UNAVAILABLE = 'unavailable';
}
