<?php

declare(strict_types=1);

namespace App\Domain\ClientFile\Entity;

use App\Domain\ClientFile\Enum\Civility;
use App\Domain\ClientFile\Enum\MaritalStatus;
use App\Domain\ClientFile\Enum\ProfessionalStatus;
use App\Domain\ClientFile\ValueObject\CivilStatus;
use App\Domain\ClientFile\ValueObject\FamilySituation;
use App\Domain\ClientFile\ValueObject\InvestmentObjective;
use App\Domain\ClientFile\ValueObject\NetWorthLine;
use App\Domain\ClientFile\ValueObject\NetWorthStatement;
use App\Domain\ClientFile\ValueObject\ProfessionalSituation;
use App\Domain\Compliance\Entity\ComplianceFolder;
use App\Domain\Workspace\Entity\Workspace;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;

use function Symfony\Component\Clock\now;

use Symfony\Component\Uid\Uuid;
use Webmozart\Assert\Assert;

/**
 * Fiche patrimoniale du client (EER, lot 1 du chantier "rapport d'adéquation" — cf. mémoire
 * cif-pilot-roadmap-phase2). Une fiche par {@see ComplianceFolder} : c'est volontairement une
 * entité satellite du dossier plutôt qu'un enrichissement d'{@see \App\Domain\Compliance\Entity\IndividualFolder},
 * qui reste l'entité de dossier (identité/statut), pas la fiche patrimoniale.
 *
 * Ne duplique jamais un champ déjà porté ailleurs : l'identité (nom/prénom/email/adresse) reste
 * sur IndividualFolder ; le profil investisseur (connaissance/expérience/tolérance/capacité)
 * reste dans le domaine Suitability. Cette fiche porte uniquement l'état civil, la situation
 * familiale/professionnelle, la situation patrimoniale détaillée et les objectifs
 * d'investissement — les données que le rapport d'adéquation devra citer.
 */
#[ORM\Entity]
#[ORM\Table(name: 'client_files')]
class ClientFile
{
    /** Nombre maximum d'objectifs d'investissement (décision actée avec le CGP pilote). */
    public const int MAX_OBJECTIVES = 3;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    public private(set) ?Uuid $id = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    public private(set) \DateTimeImmutable $updatedAt;

    // --- État civil ---
    #[ORM\Column(type: Types::STRING, nullable: true, enumType: Civility::class)]
    public private(set) ?Civility $civility = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    public private(set) ?\DateTimeImmutable $birthDate = null;

    #[ORM\Column(length: 150, nullable: true)]
    public private(set) ?string $birthPlace = null;

    #[ORM\Column(length: 100, nullable: true)]
    public private(set) ?string $nationality = null;

    // --- Situation familiale ---
    #[ORM\Column(type: Types::STRING, nullable: true, enumType: MaritalStatus::class)]
    public private(set) ?MaritalStatus $maritalStatus = null;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    public private(set) ?int $childrenCount = null;

    // --- Situation professionnelle ---
    #[ORM\Column(type: Types::STRING, nullable: true, enumType: ProfessionalStatus::class)]
    public private(set) ?ProfessionalStatus $professionalStatus = null;

    #[ORM\Column(length: 150, nullable: true)]
    public private(set) ?string $profession = null;

    #[ORM\Column(length: 150, nullable: true)]
    public private(set) ?string $employer = null;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    public private(set) ?int $professionalSeniorityYears = null;

    // --- Situation patrimoniale ---
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    public private(set) ?int $annualIncomeInCents = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    public private(set) ?int $annualExpensesInCents = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    public private(set) ?int $outstandingDebtInCents = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    public private(set) ?int $investmentCapacityInCents = null;

    /** @var array<int, array{assetClass: string, amountInCents: int}> */
    #[ORM\Column(type: Types::JSON)]
    public private(set) array $netWorthLines = [];

    // --- Objectifs d'investissement (3 maximum) ---
    /** @var array<int, array{type: string, priority: string, horizon: string, amountInCents: int|null}> */
    #[ORM\Column(type: Types::JSON)]
    public private(set) array $objectives = [];

    private function __construct(
        #[ORM\OneToOne(targetEntity: ComplianceFolder::class)]
        #[ORM\JoinColumn(unique: true, nullable: false, onDelete: 'CASCADE')]
        public private(set) ComplianceFolder $complianceFolder,
        #[ORM\ManyToOne(targetEntity: Workspace::class)]
        #[ORM\JoinColumn(nullable: false)]
        public private(set) Workspace $workspace,
    ) {
        $this->updatedAt = now();
    }

    public static function initiate(ComplianceFolder $complianceFolder): self
    {
        return new self($complianceFolder, $complianceFolder->workspace);
    }

    public function updateCivilStatus(CivilStatus $civilStatus): void
    {
        $this->civility = $civilStatus->civility;
        $this->birthDate = $civilStatus->birthDate;
        $this->birthPlace = $civilStatus->birthPlace;
        $this->nationality = $civilStatus->nationality;
        $this->updatedAt = now();
    }

    public function updateFamilySituation(FamilySituation $familySituation): void
    {
        $this->maritalStatus = $familySituation->maritalStatus;
        $this->childrenCount = $familySituation->childrenCount;
        $this->updatedAt = now();
    }

    public function updateProfessionalSituation(ProfessionalSituation $professionalSituation): void
    {
        $this->professionalStatus = $professionalSituation->status;
        $this->profession = $professionalSituation->profession;
        $this->employer = $professionalSituation->employer;
        $this->professionalSeniorityYears = $professionalSituation->seniorityYears;
        $this->updatedAt = now();
    }

    public function updateNetWorth(NetWorthStatement $netWorthStatement): void
    {
        $this->annualIncomeInCents = $netWorthStatement->annualIncomeInCents;
        $this->annualExpensesInCents = $netWorthStatement->annualExpensesInCents;
        $this->outstandingDebtInCents = $netWorthStatement->outstandingDebtInCents;
        $this->investmentCapacityInCents = $netWorthStatement->investmentCapacityInCents;
        $this->netWorthLines = array_map(static fn (NetWorthLine $line): array => $line->toArray(), $netWorthStatement->lines);
        $this->updatedAt = now();
    }

    /**
     * @param list<InvestmentObjective> $objectives
     */
    public function updateObjectives(array $objectives): void
    {
        Assert::allIsInstanceOf($objectives, InvestmentObjective::class);
        Assert::maxCount($objectives, self::MAX_OBJECTIVES, sprintf('%d objectifs d\'investissement maximum.', self::MAX_OBJECTIVES));

        $this->objectives = array_map(static fn (InvestmentObjective $objective): array => $objective->toArray(), $objectives);
        $this->updatedAt = now();
    }

    public function netWorthStatement(): ?NetWorthStatement
    {
        if (in_array(null, [$this->annualIncomeInCents, $this->annualExpensesInCents, $this->outstandingDebtInCents, $this->investmentCapacityInCents], true)) {
            return null;
        }

        return new NetWorthStatement(
            lines: array_values(array_map(NetWorthLine::fromArray(...), $this->netWorthLines)),
            annualIncomeInCents: $this->annualIncomeInCents,
            annualExpensesInCents: $this->annualExpensesInCents,
            outstandingDebtInCents: $this->outstandingDebtInCents,
            investmentCapacityInCents: $this->investmentCapacityInCents,
        );
    }

    /**
     * @return list<InvestmentObjective>
     */
    public function investmentObjectives(): array
    {
        return array_values(array_map(
            InvestmentObjective::fromArray(...),
            $this->objectives,
        ));
    }

    public function isComplete(): bool
    {
        return !in_array(null, [$this->civility, $this->maritalStatus, $this->professionalStatus, $this->netWorthStatement()], true)
            && [] !== $this->objectives;
    }
}
