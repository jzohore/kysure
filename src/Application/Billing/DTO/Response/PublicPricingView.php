<?php

declare(strict_types=1);

namespace App\Application\Billing\DTO\Response;

/**
 * Tarifs publics (vitrine) : offres d'abonnement au siège, packs de minutes et dotation d'essai.
 */
final readonly class PublicPricingView
{
    /**
     * @param list<PublicPricingOffer> $seatPlans
     * @param list<PublicPricingOffer> $minutePacks
     */
    public function __construct(
        public array $seatPlans,
        public array $minutePacks,
        public int $trialDossiers,
        public int $trialMeetingMinutes,
    ) {
    }
}
