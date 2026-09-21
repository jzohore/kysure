<?php

declare(strict_types=1);

namespace App\Domain\Compliance\Enum;

/**
 * Profil de risque « devoir de conseil » (MiFID) détecté à l'entretien, distinct
 * du {@see RiskLevel} qui, lui, relève de la LCB-FT.
 *
 * La valeur du case est le libellé affiché (français). L'IA est priée d'en
 * produire un ; {@see self::fromLabel()} tolère les écarts de casse / accents.
 */
enum AdvisoryRiskProfile: string
{
    case NON_DETERMINE = 'Non déterminé';
    case PRUDENT = 'Prudent';
    case EQUILIBRE = 'Équilibré';
    case DYNAMIQUE = 'Dynamique';
    case OFFENSIF = 'Offensif';

    /**
     * Réconcilie une chaîne libre (sortie IA, saisie) avec un case connu ;
     * casse et accents approximatifs tolérés. Retombe sur NON_DETERMINE.
     */
    public static function fromLabel(?string $label): self
    {
        $needle = self::normalize($label);

        foreach (self::cases() as $case) {
            if (self::normalize($case->value) === $needle) {
                return $case;
            }
        }

        return self::NON_DETERMINE;
    }

    /**
     * Les profils que le CGP peut choisir (liste déroulante).
     *
     * @return list<self>
     */
    public static function selectable(): array
    {
        return self::cases();
    }

    /**
     * Niveau équivalent sur l'échelle 1-7 de {@see \App\Domain\Suitability\Enum\InvestorProfileLevel}
     * (mêmes libellés partagés entre les deux échelles), utilisé pour détecter une divergence
     * significative entre le profil perçu à l'entretien et le profil validé du questionnaire
     * (décision lot 5). `null` pour NON_DETERMINE : rien à comparer tant que le CGP n'a pas
     * caractérisé de tendance à l'entretien.
     */
    public function equivalentInvestorProfileLevel(): ?int
    {
        return match ($this) {
            self::NON_DETERMINE => null,
            self::PRUDENT => 2,
            self::EQUILIBRE => 4,
            self::DYNAMIQUE => 5,
            self::OFFENSIF => 7,
        };
    }

    private static function normalize(?string $value): string
    {
        $value = mb_strtolower(trim((string) $value), 'UTF-8');

        return strtr($value, [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c',
        ]);
    }
}
