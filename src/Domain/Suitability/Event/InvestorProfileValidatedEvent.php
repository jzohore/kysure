<?php

declare(strict_types=1);

namespace App\Domain\Suitability\Event;

/**
 * Le CGP a figé le profil investisseur calculé d'un client : il devient opposable.
 */
final readonly class InvestorProfileValidatedEvent
{
    public function __construct(
        public string $profileSlugId,
        public string $clientSlugId,
        public int $version,
        public int $retainedProfileLevel,
        public bool $overridden,
        public string $validatedByName,
        /**
         * Vrai si le profil validé cumule une appétence au risque déclarée très forte et une
         * capacité à subir des pertes très faible (« trader sans filet », décision lot 5) :
         * déjà plafonné dans le score final, mais déclenche en plus une mise en garde tracée.
         */
        public bool $hasHighRiskLowCapacityMismatch = false,
    ) {
    }
}
