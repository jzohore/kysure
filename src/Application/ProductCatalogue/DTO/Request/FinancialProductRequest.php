<?php

declare(strict_types=1);

namespace App\Application\ProductCatalogue\DTO\Request;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Formulaire de création/édition d'un produit du catalogue. Les frais sont saisis en
 * pourcentage annuel (ex. 1.50) — c'est le use case qui les convertit en points de base pour
 * le stockage.
 */
class FinancialProductRequest
{
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    public ?string $name = null;

    #[Assert\Length(exactly: 12)]
    #[Assert\Regex(pattern: '/^[A-Z0-9]{12}$/', message: 'ISIN invalide (12 caractères alphanumériques en majuscules).')]
    public ?string $isin = null;

    #[Assert\NotBlank]
    public ?string $family = null;

    #[Assert\NotNull]
    #[Assert\Range(min: 1, max: 7)]
    public ?int $sriLevel = null;

    #[Assert\NotNull]
    #[Assert\GreaterThanOrEqual(0)]
    public ?int $minimumHorizonYears = null;

    #[Assert\NotNull]
    #[Assert\GreaterThanOrEqual(0)]
    public ?float $annualFeesPercent = null;

    /**
     * @var list<int>
     */
    #[Assert\Count(min: 1, minMessage: 'Sélectionnez au moins un profil investisseur cible.')]
    public array $targetInvestorProfiles = [];
}
