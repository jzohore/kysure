<?php

declare(strict_types=1);

namespace App\Application\ClientFile\UseCase;

use App\Application\ClientFile\DTO\Request\UpdateClientFileRequest;
use App\Domain\ClientFile\Entity\ClientFile;
use App\Domain\ClientFile\Enum\AssetClass;
use App\Domain\ClientFile\Enum\Civility;
use App\Domain\ClientFile\Enum\InvestmentHorizon;
use App\Domain\ClientFile\Enum\InvestmentObjectiveType;
use App\Domain\ClientFile\Enum\MaritalStatus;
use App\Domain\ClientFile\Enum\ObjectivePriority;
use App\Domain\ClientFile\Enum\ProfessionalStatus;
use App\Domain\ClientFile\Repository\ClientFileRepositoryInterface;
use App\Domain\ClientFile\ValueObject\CivilStatus;
use App\Domain\ClientFile\ValueObject\FamilySituation;
use App\Domain\ClientFile\ValueObject\InvestmentObjective;
use App\Domain\ClientFile\ValueObject\NetWorthLine;
use App\Domain\ClientFile\ValueObject\NetWorthStatement;
use App\Domain\ClientFile\ValueObject\ProfessionalSituation;
use App\Domain\Compliance\Entity\ComplianceFolder;
use App\Domain\Compliance\Repository\ComplianceFolderRepositoryInterface;
use App\Domain\Database\TransactionManagerInterface;
use Webmozart\Assert\Assert;

/**
 * Enregistre la fiche patrimoniale (EER, lot 1). Idempotent côté existence : crée la fiche au
 * premier enregistrement, la met à jour ensuite — un seul écran, pas de distinction
 * création/édition côté conseiller.
 */
readonly class UpdateClientFileUseCase
{
    private const int EUROS_TO_CENTS = 100;

    public function __construct(
        private ClientFileRepositoryInterface $clientFileRepository,
        private ComplianceFolderRepositoryInterface $complianceFolderRepository,
        private TransactionManagerInterface $transactionManager,
    ) {
    }

    public function __invoke(ComplianceFolder $complianceFolder, UpdateClientFileRequest $request): ClientFile
    {
        $clientFile = $this->clientFileRepository->findByComplianceFolder($complianceFolder)
            ?? ClientFile::initiate($complianceFolder);

        Assert::notNull($request->civility);
        Assert::notNull($request->birthDate);
        Assert::notNull($request->birthPlace);
        Assert::notNull($request->nationality);
        $clientFile->updateCivilStatus(new CivilStatus(
            civility: Civility::from($request->civility),
            birthDate: $request->birthDate,
            birthPlace: $request->birthPlace,
            nationality: $request->nationality,
        ));

        Assert::notNull($request->maritalStatus);
        $clientFile->updateFamilySituation(new FamilySituation(
            maritalStatus: MaritalStatus::from($request->maritalStatus),
            childrenCount: $request->childrenCount ?? 0,
        ));

        Assert::notNull($request->professionalStatus);
        $clientFile->updateProfessionalSituation(new ProfessionalSituation(
            status: ProfessionalStatus::from($request->professionalStatus),
            profession: $request->profession,
            employer: $request->employer,
            seniorityYears: $request->professionalSeniorityYears,
        ));

        /** @var list<array{AssetClass, int|null}> $assetAmounts */
        $assetAmounts = [
            [AssetClass::REAL_ESTATE, $request->realEstateAmount],
            [AssetClass::LIFE_INSURANCE, $request->lifeInsuranceAmount],
            [AssetClass::PEA, $request->peaAmount],
            [AssetClass::SECURITIES_ACCOUNT, $request->securitiesAccountAmount],
            [AssetClass::REGULATED_SAVINGS, $request->regulatedSavingsAmount],
            [AssetClass::AVAILABLE_LIQUIDITY, $request->availableLiquidityAmount],
        ];

        $netWorthLines = [];
        foreach ($assetAmounts as [$assetClass, $amount]) {
            $netWorthLines[] = new NetWorthLine($assetClass, ($amount ?? 0) * self::EUROS_TO_CENTS);
        }

        $clientFile->updateNetWorth(new NetWorthStatement(
            lines: $netWorthLines,
            annualIncomeInCents: ($request->annualIncome ?? 0) * self::EUROS_TO_CENTS,
            annualExpensesInCents: ($request->annualExpenses ?? 0) * self::EUROS_TO_CENTS,
            outstandingDebtInCents: ($request->outstandingDebt ?? 0) * self::EUROS_TO_CENTS,
            investmentCapacityInCents: ($request->investmentCapacity ?? 0) * self::EUROS_TO_CENTS,
        ));

        $objectives = [];
        foreach ($request->objectives as $objectiveDto) {
            if (null === $objectiveDto->type) {
                // Emplacement laissé vide par le conseiller — pas un objectif à retenir.
                continue;
            }

            Assert::notNull($objectiveDto->priority);
            Assert::notNull($objectiveDto->horizon);

            $objectives[] = new InvestmentObjective(
                type: InvestmentObjectiveType::from($objectiveDto->type),
                priority: ObjectivePriority::from($objectiveDto->priority),
                horizon: InvestmentHorizon::from($objectiveDto->horizon),
                amountInCents: null !== $objectiveDto->amount ? $objectiveDto->amount * self::EUROS_TO_CENTS : null,
            );
        }
        $clientFile->updateObjectives($objectives);

        $complianceFolder->saveHistory('Fiche patrimoniale mise à jour (EER)');

        $this->transactionManager->transactional(function () use ($clientFile, $complianceFolder): void {
            $this->clientFileRepository->save($clientFile);
            $this->complianceFolderRepository->save($complianceFolder);
        });

        return $clientFile;
    }
}
