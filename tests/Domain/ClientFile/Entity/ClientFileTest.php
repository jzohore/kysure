<?php

declare(strict_types=1);

namespace App\Tests\Domain\ClientFile\Entity;

use App\Domain\ClientFile\Entity\ClientFile;
use App\Domain\ClientFile\Enum\AssetClass;
use App\Domain\ClientFile\Enum\Civility;
use App\Domain\ClientFile\Enum\InvestmentHorizon;
use App\Domain\ClientFile\Enum\InvestmentObjectiveType;
use App\Domain\ClientFile\Enum\MaritalStatus;
use App\Domain\ClientFile\Enum\ObjectivePriority;
use App\Domain\ClientFile\Enum\ProfessionalStatus;
use App\Domain\ClientFile\ValueObject\CivilStatus;
use App\Domain\ClientFile\ValueObject\FamilySituation;
use App\Domain\ClientFile\ValueObject\InvestmentObjective;
use App\Domain\ClientFile\ValueObject\NetWorthLine;
use App\Domain\ClientFile\ValueObject\NetWorthStatement;
use App\Domain\ClientFile\ValueObject\ProfessionalSituation;
use App\Domain\Compliance\Entity\IndividualFolder;
use App\Domain\Workspace\Entity\Workspace;
use PHPUnit\Framework\TestCase;

final class ClientFileTest extends TestCase
{
    private function complianceFolder(): IndividualFolder
    {
        return IndividualFolder::createDraft($this->createStub(Workspace::class), 'KYC-TEST-01', 'client@example.com', 'manual');
    }

    public function testInitiateAttachesToTheComplianceFolderAndItsWorkspace(): void
    {
        $folder = $this->complianceFolder();

        $clientFile = ClientFile::initiate($folder);

        self::assertSame($folder, $clientFile->complianceFolder);
        self::assertSame($folder->workspace, $clientFile->workspace);
        self::assertFalse($clientFile->isComplete());
    }

    public function testUpdateNetWorthIsReadableBackAsAStatement(): void
    {
        $clientFile = ClientFile::initiate($this->complianceFolder());

        $clientFile->updateNetWorth(new NetWorthStatement(
            lines: [
                new NetWorthLine(AssetClass::REAL_ESTATE, 180_000_00),
                new NetWorthLine(AssetClass::AVAILABLE_LIQUIDITY, 12_000_00),
            ],
            annualIncomeInCents: 96_000_00,
            annualExpensesInCents: 38_000_00,
            outstandingDebtInCents: 24_000_00,
            investmentCapacityInCents: 35_000_00,
        ));

        $statement = $clientFile->netWorthStatement();

        self::assertNotNull($statement);
        self::assertSame(192_000_00, $statement->grossWorth());
        self::assertSame(168_000_00, $statement->netWorth());
    }

    public function testUpdateObjectivesRejectsMoreThanThreeObjectives(): void
    {
        $clientFile = ClientFile::initiate($this->complianceFolder());

        $this->expectException(\InvalidArgumentException::class);

        $clientFile->updateObjectives([
            new InvestmentObjective(InvestmentObjectiveType::RETIREMENT_PREPARATION, ObjectivePriority::PRIMARY, InvestmentHorizon::FROM_8_TO_10_YEARS),
            new InvestmentObjective(InvestmentObjectiveType::CAPITAL_GROWTH, ObjectivePriority::SECONDARY, InvestmentHorizon::FROM_5_TO_8_YEARS),
            new InvestmentObjective(InvestmentObjectiveType::TAX_OPTIMIZATION, ObjectivePriority::SECONDARY, InvestmentHorizon::FROM_3_TO_5_YEARS),
            new InvestmentObjective(InvestmentObjectiveType::OTHER, ObjectivePriority::SECONDARY, InvestmentHorizon::UNDER_3_YEARS),
        ]);
    }

    public function testUpdateObjectivesIsReadableBackInOrder(): void
    {
        $clientFile = ClientFile::initiate($this->complianceFolder());

        $objectives = [
            new InvestmentObjective(InvestmentObjectiveType::RETIREMENT_PREPARATION, ObjectivePriority::PRIMARY, InvestmentHorizon::FROM_8_TO_10_YEARS, 150_000_00),
            new InvestmentObjective(InvestmentObjectiveType::CAPITAL_GROWTH, ObjectivePriority::SECONDARY, InvestmentHorizon::FROM_5_TO_8_YEARS, 50_000_00),
        ];
        $clientFile->updateObjectives($objectives);

        $stored = $clientFile->investmentObjectives();

        self::assertCount(2, $stored);
        self::assertSame(InvestmentObjectiveType::RETIREMENT_PREPARATION, $stored[0]->type);
        self::assertSame(150_000_00, $stored[0]->amountInCents);
    }

    public function testIsCompleteOnceEverySectionIsFilled(): void
    {
        $clientFile = ClientFile::initiate($this->complianceFolder());

        $clientFile->updateCivilStatus(new CivilStatus(Civility::MRS, new \DateTimeImmutable('1979-03-12'), 'Lyon', 'Française'));
        $clientFile->updateFamilySituation(new FamilySituation(MaritalStatus::MARRIED, 2));
        $clientFile->updateProfessionalSituation(new ProfessionalSituation(ProfessionalStatus::EXECUTIVE));
        $clientFile->updateNetWorth(new NetWorthStatement([], 96_000_00, 38_000_00, 0, 35_000_00));
        $clientFile->updateObjectives([
            new InvestmentObjective(InvestmentObjectiveType::RETIREMENT_PREPARATION, ObjectivePriority::PRIMARY, InvestmentHorizon::FROM_8_TO_10_YEARS),
        ]);

        self::assertTrue($clientFile->isComplete());
    }
}
