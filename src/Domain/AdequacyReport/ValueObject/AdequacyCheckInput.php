<?php

declare(strict_types=1);

namespace App\Domain\AdequacyReport\ValueObject;

use App\Domain\ClientFile\ValueObject\InvestmentObjective;
use App\Domain\ClientFile\ValueObject\NetWorthLine;
use App\Domain\ClientFile\ValueObject\NetWorthStatement;
use App\Domain\Suitability\Enum\InvestorProfileLevel;
use App\Domain\Suitability\Enum\ProductFamily;
use App\Domain\Suitability\Enum\SustainabilityPreference;
use App\Domain\Suitability\ValueObject\ExperienceAnswers;
use App\Domain\Suitability\ValueObject\FinancialKnowledgeAnswers;
use Webmozart\Assert\Assert;

/**
 * Tout ce que le moteur d'adéquation confronte : le profil investisseur validé (qui fait foi),
 * la fiche patrimoniale (EER), l'objectif visé, le produit choisi par le CGP et le montant.
 */
final readonly class AdequacyCheckInput
{
    public function __construct(
        public InvestorProfileLevel $retainedProfile,
        public FinancialKnowledgeAnswers $knowledge,
        public ExperienceAnswers $experience,
        public SustainabilityPreference $sustainabilityPreference,
        public NetWorthStatement $netWorth,
        public InvestmentObjective $objective,
        public ProductCharacteristics $product,
        public int $amountInCents,
    ) {
        Assert::greaterThan($amountInCents, 0, 'Le montant investi doit être positif.');
    }

    /**
     * Instantané des données d'entrée réellement utilisées, figé avec la vérification.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'retainedProfile' => $this->retainedProfile->value,
            'knowledgeOfProductFamily' => $this->knowledge->levelFor($this->product->family)->value,
            'productFamiliesHeld' => array_map(static fn (ProductFamily $family): string => $family->value, $this->experience->productsHeld),
            'sustainabilityPreference' => $this->sustainabilityPreference->value,
            'netWorth' => [
                'lines' => array_map(static fn (NetWorthLine $line): array => $line->toArray(), $this->netWorth->lines),
                'annualIncomeInCents' => $this->netWorth->annualIncomeInCents,
                'annualExpensesInCents' => $this->netWorth->annualExpensesInCents,
                'outstandingDebtInCents' => $this->netWorth->outstandingDebtInCents,
                'investmentCapacityInCents' => $this->netWorth->investmentCapacityInCents,
            ],
            'objective' => $this->objective->toArray(),
            'product' => $this->product->toArray(),
            'amountInCents' => $this->amountInCents,
        ];
    }
}
