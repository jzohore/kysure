<?php

declare(strict_types=1);

namespace App\Domain\Suitability\Enum;

/**
 * Profil investisseur sur l'échelle réglementaire 1-7 (§3.6). Distinct de
 * {@see \App\Domain\Compliance\Enum\AdvisoryRiskProfile}, qui reste la tendance perçue par le
 * CGP à l'entretien : ce profil-ci, issu du questionnaire à réponses tracées, fait foi.
 *
 * Ce score est une donnée d'entrée validée par le CGP, jamais une recommandation produit :
 * ne pas le présenter comme un conseil.
 */
enum InvestorProfileLevel: int
{
    case TRES_PRUDENT = 1;
    case PRUDENT = 2;
    case MODERE = 3;
    case EQUILIBRE = 4;
    case DYNAMIQUE = 5;
    case TRES_DYNAMIQUE = 6;
    case AGRESSIF = 7;

    public function getLabel(): string
    {
        return match ($this) {
            self::TRES_PRUDENT => 'Très prudent',
            self::PRUDENT => 'Prudent',
            self::MODERE => 'Modéré',
            self::EQUILIBRE => 'Équilibré',
            self::DYNAMIQUE => 'Dynamique',
            self::TRES_DYNAMIQUE => 'Très dynamique',
            self::AGRESSIF => 'Agressif / très forte exposition au risque',
        };
    }

    /**
     * Convertit un score continu (échelle 1-7) en palier entier, en bornant les débordements
     * dus aux arrondis de pondération plutôt que de lever une erreur.
     */
    public static function fromScore(float $score): self
    {
        $bounded = max(1.0, min(7.0, $score));

        return self::from((int) round($bounded));
    }
}
