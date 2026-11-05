<?php

declare(strict_types=1);

namespace App\Tests\Domain\AdequacyReport;

use App\Domain\AdequacyReport\ValueObject\AdequacyCheckInput;
use App\Domain\AdequacyReport\ValueObject\ProductCharacteristics;
use App\Domain\ClientFile\Enum\AssetClass;
use App\Domain\ClientFile\Enum\InvestmentHorizon;
use App\Domain\ClientFile\Enum\InvestmentObjectiveType;
use App\Domain\ClientFile\Enum\ObjectivePriority;
use App\Domain\ClientFile\ValueObject\InvestmentObjective;
use App\Domain\ClientFile\ValueObject\NetWorthLine;
use App\Domain\ClientFile\ValueObject\NetWorthStatement;
use App\Domain\ProductCatalogue\Enum\SfdrClassification;
use App\Domain\Suitability\Enum\InvestorProfileLevel;
use App\Domain\Suitability\Enum\KnowledgeLevel;
use App\Domain\Suitability\Enum\ProductFamily;
use App\Domain\Suitability\Enum\SustainabilityPreference;
use App\Domain\Suitability\Enum\TransactionFrequency;
use App\Domain\Suitability\ValueObject\ExperienceAnswers;
use App\Domain\Suitability\ValueObject\FinancialKnowledgeAnswers;

/**
 * Scénario de référence où tous les contrôles sont conformes : profil équilibré (4), ETF SRI 4
 * horizon 5 ans, objectif 5-8 ans, 20 000 € sur un patrimoine net de 280 000 €. Chaque test ne
 * modifie que le paramètre qu'il éprouve.
 */
final class AdequacyCheckInputFactory
{
    /**
     * @param list<InvestorProfileLevel> $targetProfiles
     * @param list<ProductFamily>        $productsHeld
     */
    public static function create(
        InvestorProfileLevel $profile = InvestorProfileLevel::EQUILIBRE,
        ProductFamily $family = ProductFamily::OPCVM_ETF,
        int $sriLevel = 4,
        int $productHorizonYears = 5,
        array $targetProfiles = [InvestorProfileLevel::EQUILIBRE, InvestorProfileLevel::DYNAMIQUE],
        ?SfdrClassification $sfdr = SfdrClassification::ARTICLE_8,
        KnowledgeLevel $knowledge = KnowledgeLevel::BONNE,
        array $productsHeld = [ProductFamily::OPCVM_ETF],
        SustainabilityPreference $sustainability = SustainabilityPreference::SANS_PREFERENCE,
        InvestmentHorizon $objectiveHorizon = InvestmentHorizon::FROM_5_TO_8_YEARS,
        int $liquidityInCents = 5_000_000,
        int $regulatedSavingsInCents = 3_000_000,
        int $realEstateInCents = 30_000_000,
        int $debtInCents = 10_000_000,
        int $annualExpensesInCents = 4_000_000,
        int $capacityInCents = 6_000_000,
        int $amountInCents = 2_000_000,
    ): AdequacyCheckInput {
        $levels = [];
        foreach (ProductFamily::cases() as $case) {
            $levels[$case->value] = $knowledge;
        }

        return new AdequacyCheckInput(
            retainedProfile: $profile,
            knowledge: FinancialKnowledgeAnswers::fromLevels($levels),
            experience: ExperienceAnswers::fromAnswers($productsHeld, TransactionFrequency::REGULIERE, 10_000.0, 5, 10, false),
            sustainabilityPreference: $sustainability,
            netWorth: new NetWorthStatement(
                lines: [
                    new NetWorthLine(AssetClass::REAL_ESTATE, $realEstateInCents),
                    new NetWorthLine(AssetClass::AVAILABLE_LIQUIDITY, $liquidityInCents),
                    new NetWorthLine(AssetClass::REGULATED_SAVINGS, $regulatedSavingsInCents),
                ],
                annualIncomeInCents: 8_000_000,
                annualExpensesInCents: $annualExpensesInCents,
                outstandingDebtInCents: $debtInCents,
                investmentCapacityInCents: $capacityInCents,
            ),
            objective: new InvestmentObjective(InvestmentObjectiveType::CAPITAL_GROWTH, ObjectivePriority::PRIMARY, $objectiveHorizon),
            product: new ProductCharacteristics(
                name: 'ETF MSCI World',
                isin: 'FR0000000102',
                family: $family,
                sriLevel: $sriLevel,
                minimumHorizonYears: $productHorizonYears,
                annualFeesBasisPoints: 30,
                targetInvestorProfiles: $targetProfiles,
                sfdrClassification: $sfdr,
            ),
            amountInCents: $amountInCents,
        );
    }
}
