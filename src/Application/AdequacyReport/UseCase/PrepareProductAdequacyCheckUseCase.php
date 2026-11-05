<?php

declare(strict_types=1);

namespace App\Application\AdequacyReport\UseCase;

use App\Application\AdequacyReport\DTO\PreparedAdequacyCheck;
use App\Domain\AdequacyReport\ValueObject\AdequacyCheckInput;
use App\Domain\AdequacyReport\ValueObject\ProductCharacteristics;
use App\Domain\ClientFile\Entity\ClientFile;
use App\Domain\ClientFile\Repository\ClientFileRepositoryInterface;
use App\Domain\ClientFile\ValueObject\InvestmentObjective;
use App\Domain\ClientFile\ValueObject\NetWorthStatement;
use App\Domain\Compliance\Entity\ComplianceFolder;
use App\Domain\ProductCatalogue\Entity\FinancialProduct;
use App\Domain\ProductCatalogue\Repository\FinancialProductRepositoryInterface;
use App\Domain\Suitability\Entity\ValidatedInvestorProfile;
use App\Domain\Suitability\Enum\InvestorProfileLevel;
use App\Domain\Suitability\Repository\ValidatedInvestorProfileRepositoryInterface;
use App\Domain\Suitability\Service\AssessmentAnswersAssembler;
use App\Domain\User\Entity\Client;

/**
 * Rassemble les entrées du moteur d'adéquation pour un dossier : profil investisseur EN
 * VIGUEUR dans le cabinet du dossier, fiche patrimoniale complète (situation + objectif choisi),
 * produit actif du catalogue de ce même cabinet. Chaque prérequis manquant lève une
 * {@see \DomainException} au message actionnable par le CGP.
 */
final readonly class PrepareProductAdequacyCheckUseCase
{
    public function __construct(
        private ValidatedInvestorProfileRepositoryInterface $profileRepository,
        private ClientFileRepositoryInterface $clientFileRepository,
        private FinancialProductRepositoryInterface $productRepository,
        private AssessmentAnswersAssembler $answersAssembler,
    ) {
    }

    public function __invoke(ComplianceFolder $folder, string $productSlugId, int $objectiveIndex, int $amountInCents): PreparedAdequacyCheck
    {
        if ($amountInCents <= 0) {
            throw new \DomainException('Indiquez un montant à investir.');
        }

        $profile = $this->findInForceProfile($folder);
        $clientFile = $this->clientFileRepository->findByComplianceFolder($folder);
        $netWorth = $clientFile?->netWorthStatement();
        if (!$clientFile instanceof ClientFile || !$netWorth instanceof NetWorthStatement) {
            throw new \DomainException('Complétez la situation patrimoniale de la fiche patrimoniale (EER) avant de vérifier un produit.');
        }

        $objective = $clientFile->investmentObjectives()[$objectiveIndex] ?? null;
        if (!$objective instanceof InvestmentObjective) {
            throw new \DomainException('Choisissez l\'objectif d\'investissement concerné.');
        }

        $product = $this->productRepository->findBySlugIdAndWorkspace($productSlugId, $folder->workspace);
        if (!$product instanceof FinancialProduct || $product->isArchived()) {
            throw new \DomainException('Choisissez un produit actif du catalogue du cabinet.');
        }

        $answers = $this->answersAssembler->assemble($this->answersOf($profile));

        return new PreparedAdequacyCheck(
            investorProfile: $profile,
            product: $product,
            input: new AdequacyCheckInput(
                retainedProfile: InvestorProfileLevel::from($profile->retainedProfileLevel()),
                knowledge: $answers->knowledge,
                experience: $answers->experience,
                sustainabilityPreference: $answers->sustainability->preference,
                netWorth: $netWorth,
                objective: $objective,
                product: ProductCharacteristics::fromProduct($product),
                amountInCents: $amountInCents,
            ),
        );
    }

    private function findInForceProfile(ComplianceFolder $folder): ValidatedInvestorProfile
    {
        $profile = $folder->client instanceof Client
            ? $this->profileRepository->findInForceByClient($folder->client, $folder->workspace)
            : null;

        if (!$profile instanceof ValidatedInvestorProfile) {
            throw new \DomainException('Aucun profil investisseur validé n\'est en vigueur pour ce client : validez-le avant de vérifier un produit.');
        }

        return $profile;
    }

    /**
     * Réponses figées du profil validé (et non celles de l'assessment, qui a pu bouger depuis).
     *
     * @return array<string, mixed>
     */
    private function answersOf(ValidatedInvestorProfile $profile): array
    {
        /** @var array<string, mixed> $answers */
        $answers = $profile->content['answers'];

        return $answers;
    }
}
