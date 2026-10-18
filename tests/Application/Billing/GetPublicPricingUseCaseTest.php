<?php

declare(strict_types=1);

namespace App\Tests\Application\Billing;

use App\Application\Billing\DTO\Response\PublicPricingOffer;
use App\Application\Billing\UseCase\Pricing\GetPublicPricingUseCase;
use App\Domain\Billing\Entity\PricingPlan;
use App\Domain\Billing\Enum\PricingPlanKind;
use App\Domain\Billing\Repository\PricingPlanRepositoryInterface;
use App\Domain\Workspace\Entity\Workspace;
use PHPUnit\Framework\TestCase;

final class GetPublicPricingUseCaseTest extends TestCase
{
    /**
     * @param array<string, list<PricingPlan>> $rowsByKind
     */
    private function useCase(array $rowsByKind = []): GetPublicPricingUseCase
    {
        $repository = $this->createStub(PricingPlanRepositoryInterface::class);
        $repository->method('findByKind')->willReturnCallback(
            static fn (PricingPlanKind $kind): array => $rowsByKind[$kind->value] ?? []
        );

        return new GetPublicPricingUseCase($repository);
    }

    public function testFallsBackToLaunchPricesWhenTheDatabaseIsNotProvisioned(): void
    {
        $view = ($this->useCase())();

        self::assertSame(['Indépendant', 'Cabinet'], array_map(static fn (PublicPricingOffer $o): string => $o->label, $view->seatPlans));
        self::assertSame([9900, 7900], array_map(static fn (PublicPricingOffer $o): int => $o->amountCents, $view->seatPlans));
        self::assertSame([1, 2], array_map(static fn (PublicPricingOffer $o): int => $o->minSeats, $view->seatPlans));
        self::assertSame([300, 600, 1500], array_map(static fn (PublicPricingOffer $o): int => $o->minutes, $view->minutePacks));
        self::assertSame(Workspace::TRIAL_DOSSIERS, $view->trialDossiers);
        self::assertSame(Workspace::TRIAL_MEETING_MINUTES, $view->trialMeetingMinutes);
    }

    public function testDatabasePricesTakePrecedenceOverLaunchPrices(): void
    {
        $view = ($this->useCase([
            PricingPlanKind::SEAT_SUBSCRIPTION->value => [
                PricingPlan::create('cabinet_seat', PricingPlanKind::SEAT_SUBSCRIPTION, 'Cabinet Pro', 8900, 200, 3),
            ],
            PricingPlanKind::MINUTE_PACK->value => [
                PricingPlan::create('minutes_300', PricingPlanKind::MINUTE_PACK, '300 minutes', 3500, 300),
            ],
        ]))();

        // Offre provisionnée : valeurs de la base ; offre non provisionnée : repli sur le prix de lancement.
        self::assertSame('Indépendant', $view->seatPlans[0]->label);
        self::assertSame(9900, $view->seatPlans[0]->amountCents);
        self::assertSame('Cabinet', $view->seatPlans[1]->label);
        self::assertSame(8900, $view->seatPlans[1]->amountCents);
        self::assertSame(200, $view->seatPlans[1]->minutes);
        self::assertSame(3, $view->seatPlans[1]->minSeats);
        self::assertSame(3500, $view->minutePacks[0]->amountCents);
        self::assertSame(4900, $view->minutePacks[1]->amountCents);
    }

    public function testFormattedAmountDropsUselessDecimals(): void
    {
        self::assertSame('99', new PublicPricingOffer('x', 9900, 0)->formattedAmount());
        self::assertSame('29,50', new PublicPricingOffer('x', 2950, 0)->formattedAmount());
        self::assertSame("1\u{202F}200", new PublicPricingOffer('x', 120000, 0)->formattedAmount());
    }
}
