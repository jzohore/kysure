<?php

declare(strict_types=1);

namespace App\Domain\Suitability\ValueObject;

use App\Domain\Suitability\Enum\SustainabilityPreference;

/**
 * Réponses au questionnaire de préférences de durabilité (§3.7). Ne contribue volontairement
 * pas au score du profil investisseur : ces préférences serviront, dans un lot ultérieur, à
 * filtrer les produits proposés — pas à moduler l'appétence au risque.
 */
final readonly class SustainabilityAnswers
{
    private function __construct(
        public SustainabilityPreference $preference,
        public ?string $specificConstraints,
    ) {
    }

    public static function fromAnswers(
        SustainabilityPreference $preference,
        ?string $specificConstraints = null,
    ): self {
        return new self($preference, $specificConstraints);
    }
}
