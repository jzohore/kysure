<?php

declare(strict_types=1);

namespace App\Application\ClientFile\DTO\Request;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Formulaire de la fiche patrimoniale (EER, lot 1). Tous les montants sont saisis en euros
 * entiers (aucun besoin de centimes à la saisie pour une estimation patrimoniale) — c'est le
 * use case qui les convertit en centimes pour le stockage.
 */
class UpdateClientFileRequest
{
    // --- État civil ---
    #[Assert\NotBlank]
    public ?string $civility = null;

    #[Assert\NotNull]
    public ?\DateTimeImmutable $birthDate = null;

    #[Assert\NotBlank]
    #[Assert\Length(max: 150)]
    public ?string $birthPlace = null;

    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    public ?string $nationality = null;

    // --- Situation familiale ---
    #[Assert\NotBlank]
    public ?string $maritalStatus = null;

    #[Assert\NotNull]
    #[Assert\GreaterThanOrEqual(0)]
    public ?int $childrenCount = 0;

    // --- Situation professionnelle ---
    #[Assert\NotBlank]
    public ?string $professionalStatus = null;

    #[Assert\Length(max: 150)]
    public ?string $profession = null;

    #[Assert\Length(max: 150)]
    public ?string $employer = null;

    #[Assert\GreaterThanOrEqual(0)]
    public ?int $professionalSeniorityYears = null;

    // --- Situation patrimoniale ---
    #[Assert\NotNull]
    #[Assert\GreaterThanOrEqual(0)]
    public ?int $annualIncome = null;

    #[Assert\NotNull]
    #[Assert\GreaterThanOrEqual(0)]
    public ?int $annualExpenses = null;

    #[Assert\NotNull]
    #[Assert\GreaterThanOrEqual(0)]
    public ?int $outstandingDebt = 0;

    #[Assert\NotNull]
    #[Assert\GreaterThanOrEqual(0)]
    public ?int $investmentCapacity = null;

    #[Assert\NotNull]
    #[Assert\GreaterThanOrEqual(0)]
    public ?int $realEstateAmount = 0;

    #[Assert\NotNull]
    #[Assert\GreaterThanOrEqual(0)]
    public ?int $lifeInsuranceAmount = 0;

    #[Assert\NotNull]
    #[Assert\GreaterThanOrEqual(0)]
    public ?int $peaAmount = 0;

    #[Assert\NotNull]
    #[Assert\GreaterThanOrEqual(0)]
    public ?int $securitiesAccountAmount = 0;

    #[Assert\NotNull]
    #[Assert\GreaterThanOrEqual(0)]
    public ?int $regulatedSavingsAmount = 0;

    #[Assert\NotNull]
    #[Assert\GreaterThanOrEqual(0)]
    public ?int $availableLiquidityAmount = 0;

    // --- Objectifs d'investissement (3 emplacements fixes, cf. ClientFile::MAX_OBJECTIVES) ---
    /**
     * @var ClientFileObjectiveDTO[]
     */
    #[Assert\Valid]
    public array $objectives = [];

    public function __construct()
    {
        $this->objectives = [
            new ClientFileObjectiveDTO(),
            new ClientFileObjectiveDTO(),
            new ClientFileObjectiveDTO(),
        ];
    }
}
