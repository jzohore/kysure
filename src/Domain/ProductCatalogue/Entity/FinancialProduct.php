<?php

declare(strict_types=1);

namespace App\Domain\ProductCatalogue\Entity;

use App\Domain\Suitability\Enum\InvestorProfileLevel;
use App\Domain\Suitability\Enum\ProductFamily;
use App\Domain\Workspace\Entity\Workspace;
use App\Infrastructure\Trait\GenerateSlugPrefixedTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;

use function Symfony\Component\Clock\now;

use Symfony\Component\Uid\Uuid;
use Webmozart\Assert\Assert;

/**
 * Support financier du catalogue du cabinet (lot 2 du chantier "rapport d'adéquation" — cf.
 * mémoire cif-pilot-roadmap-phase2). Saisie manuelle par le cabinet, pas d'import ni de
 * connexion à une source de données de marché — c'est le cabinet lui-même qui alimente son
 * propre catalogue (20-40 supports récurrents, hypothèse à confirmer avec le CGP pilote).
 *
 * Un produit n'est jamais supprimé, seulement archivé : un rapport d'adéquation (lot 5) copiera
 * un instantané de ces données au moment de sa validation, mais rien ne doit empêcher de
 * retrouver un produit référencé par un rapport passé.
 */
#[ORM\Entity]
#[ORM\Table(name: 'financial_products')]
class FinancialProduct
{
    use GenerateSlugPrefixedTrait;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    public private(set) ?Uuid $id = null;

    #[ORM\Column(type: Types::STRING, length: 255, unique: true)]
    public private(set) string $slugId;

    #[ORM\Column(type: Types::STRING, length: 255)]
    public private(set) string $name;

    #[ORM\Column(type: Types::STRING, length: 12, nullable: true)]
    public private(set) ?string $isin = null;

    #[ORM\Column(type: Types::STRING, enumType: ProductFamily::class)]
    public private(set) ProductFamily $family;

    /** Échelle de risque et de rendement (SRI, DIC PRIIPS), 1 à 7. */
    #[ORM\Column(type: Types::SMALLINT)]
    public private(set) int $sriLevel;

    #[ORM\Column(type: Types::SMALLINT)]
    public private(set) int $minimumHorizonYears;

    /** Frais annuels moyens, en points de base (150 = 1,50 %/an) — jamais de flottant sur un taux. */
    #[ORM\Column(type: Types::INTEGER)]
    public private(set) int $annualFeesBasisPoints;

    /** @var list<int> valeurs de {@see InvestorProfileLevel} compatibles avec ce produit */
    #[ORM\Column(type: Types::JSON)]
    public private(set) array $targetInvestorProfiles;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public private(set) ?\DateTimeImmutable $archivedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    public private(set) \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    public private(set) \DateTimeImmutable $updatedAt;

    private function __construct(
        #[ORM\ManyToOne(targetEntity: Workspace::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public private(set) Workspace $workspace,
    ) {
        $this->slugId = $this->generate_ulid_prefixed('fin_prod_');
        $this->createdAt = now();
        $this->updatedAt = now();
    }

    /**
     * @param list<int> $targetInvestorProfiles
     */
    public static function create(
        Workspace $workspace,
        string $name,
        ?string $isin,
        ProductFamily $family,
        int $sriLevel,
        int $minimumHorizonYears,
        int $annualFeesBasisPoints,
        array $targetInvestorProfiles,
    ): self {
        $product = new self($workspace);
        $product->update($name, $isin, $family, $sriLevel, $minimumHorizonYears, $annualFeesBasisPoints, $targetInvestorProfiles);

        return $product;
    }

    /**
     * @param list<int> $targetInvestorProfiles
     */
    public function update(
        string $name,
        ?string $isin,
        ProductFamily $family,
        int $sriLevel,
        int $minimumHorizonYears,
        int $annualFeesBasisPoints,
        array $targetInvestorProfiles,
    ): void {
        Assert::notWhitespaceOnly($name, 'Le nom du produit est obligatoire.');
        Assert::true(null === $isin || 1 === preg_match('/^[A-Z0-9]{12}$/', $isin), 'ISIN invalide (12 caractères alphanumériques en majuscules).');
        Assert::range($sriLevel, 1, 7, 'Le SRI doit être compris entre 1 et 7.');
        Assert::greaterThanEq($minimumHorizonYears, 0, 'L\'horizon minimum ne peut pas être négatif.');
        Assert::greaterThanEq($annualFeesBasisPoints, 0, 'Les frais annuels ne peuvent pas être négatifs.');
        Assert::notEmpty($targetInvestorProfiles, 'Sélectionnez au moins un profil investisseur cible.');
        foreach ($targetInvestorProfiles as $level) {
            Assert::notNull(InvestorProfileLevel::tryFrom($level), 'Profil investisseur cible invalide.');
        }

        $this->name = trim($name);
        $this->isin = $isin;
        $this->family = $family;
        $this->sriLevel = $sriLevel;
        $this->minimumHorizonYears = $minimumHorizonYears;
        $this->annualFeesBasisPoints = $annualFeesBasisPoints;
        $this->targetInvestorProfiles = $targetInvestorProfiles;
        $this->updatedAt = now();
    }

    public function archive(): void
    {
        if ($this->archivedAt instanceof \DateTimeImmutable) {
            throw new \DomainException('Ce produit est déjà archivé.');
        }

        $this->archivedAt = now();
        $this->updatedAt = now();
    }

    public function reactivate(): void
    {
        if (!$this->archivedAt instanceof \DateTimeImmutable) {
            throw new \DomainException('Ce produit n\'est pas archivé.');
        }

        $this->archivedAt = null;
        $this->updatedAt = now();
    }

    public function isArchived(): bool
    {
        return $this->archivedAt instanceof \DateTimeImmutable;
    }

    /**
     * @return list<InvestorProfileLevel>
     */
    public function targetInvestorProfileLevels(): array
    {
        return array_map(InvestorProfileLevel::from(...), $this->targetInvestorProfiles);
    }
}
