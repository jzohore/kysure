<?php

declare(strict_types=1);

namespace App\Application\Billing\UseCase\Pricing;

use App\Application\Billing\DTO\Response\PublicPricingOffer;
use App\Application\Billing\DTO\Response\PublicPricingView;
use App\Domain\Billing\Entity\PricingPlan;
use App\Domain\Billing\Enum\MinutePack;
use App\Domain\Billing\Enum\Plan;
use App\Domain\Billing\Enum\PricingPlanKind;
use App\Domain\Billing\Repository\PricingPlanRepositoryInterface;
use App\Domain\Workspace\Entity\Workspace;

/**
 * Tarifs affichés sur la vitrine : la source de vérité est la table `PricingPlan` (celle de la page
 * de facturation et de Stripe). Les prix de lancement des enums ne servent que de repli, offre par
 * offre, tant que `app:billing:sync-pricing` n'a pas provisionné la base : la vitrine ne dérive donc
 * jamais de ce que le client paiera réellement. Les intitulés restent ceux des enums (sobres : les
 * libellés en base, destinés à Stripe, sont plus longs).
 */
readonly class GetPublicPricingUseCase
{
    public function __construct(
        private PricingPlanRepositoryInterface $pricingPlanRepository,
    ) {
    }

    public function __invoke(): PublicPricingView
    {
        $seatRows = $this->indexByKey(PricingPlanKind::SEAT_SUBSCRIPTION);
        $seatPlans = [];
        foreach (Plan::cases() as $plan) {
            $row = $seatRows[$plan->getPricingKey()] ?? null;
            $seatPlans[] = $row instanceof PricingPlan
                ? new PublicPricingOffer($plan->getLabel(), $row->unitAmountCents, $row->meetingMinutes, $row->minSeats)
                : new PublicPricingOffer(
                    $plan->getLabel(),
                    $plan->getMonthlyPriceCentsPerSeat(),
                    $plan->getIncludedMinutesPerSeat(),
                    $plan->getMinSeats(),
                );
        }

        $packRows = $this->indexByKey(PricingPlanKind::MINUTE_PACK);
        $minutePacks = [];
        foreach (MinutePack::cases() as $pack) {
            $row = $packRows[$pack->getPricingKey()] ?? null;
            $minutePacks[] = $row instanceof PricingPlan
                ? new PublicPricingOffer($pack->getLabel(), $row->unitAmountCents, $row->meetingMinutes)
                : new PublicPricingOffer($pack->getLabel(), $pack->getPriceCents(), $pack->getMinutes());
        }

        return new PublicPricingView(
            seatPlans: $seatPlans,
            minutePacks: $minutePacks,
            trialDossiers: Workspace::TRIAL_DOSSIERS,
            trialMeetingMinutes: Workspace::TRIAL_MEETING_MINUTES,
        );
    }

    /**
     * @return array<string, PricingPlan>
     */
    private function indexByKey(PricingPlanKind $kind): array
    {
        $indexed = [];
        foreach ($this->pricingPlanRepository->findByKind($kind) as $row) {
            $indexed[$row->planKey] = $row;
        }

        return $indexed;
    }
}
