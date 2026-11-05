<?php

declare(strict_types=1);

namespace App\Domain\AdequacyReport\Entity;

use App\Domain\AdequacyReport\Enum\MatchSeverity;
use App\Domain\AdequacyReport\Service\SuitabilityMatchEvaluator;
use App\Domain\AdequacyReport\ValueObject\AdequacyCheckInput;
use App\Domain\AdequacyReport\ValueObject\AdequacyEvaluation;
use App\Domain\Compliance\Entity\ComplianceFolder;
use App\Domain\ProductCatalogue\Entity\FinancialProduct;
use App\Domain\Suitability\Entity\ValidatedInvestorProfile;
use App\Domain\User\Entity\User;
use App\Domain\Workspace\Entity\Workspace;
use App\Infrastructure\Trait\GenerateSlugPrefixedTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;

use function Symfony\Component\Clock\now;

use Symfony\Component\Uid\Uuid;

/**
 * Vérification d'adéquation figée (lot 3, issue #30) : le CGP a confronté un produit du
 * catalogue à la situation du client, et enregistre le résultat. Ligne en ajout seul, jamais
 * modifiée : une nouvelle vérification crée une nouvelle ligne.
 *
 * Le contenu (entrées réellement utilisées + constats + version du moteur) est copié et
 * scellé par une empreinte SHA-256, sur le modèle de {@see ValidatedInvestorProfile} : la
 * vérification reste lisible et prouvable même si le produit, la fiche patrimoniale ou le
 * moteur évoluent ensuite. Le rapport d'adéquation (lot 5) citera ces vérifications.
 *
 * Un constat bloquant n'empêche pas l'enregistrement, mais exige une justification écrite.
 */
#[ORM\Entity]
#[ORM\Table(name: 'adequacy_product_checks')]
#[ORM\Index(name: 'idx_adequacy_check_folder', columns: ['compliance_folder_id', 'checked_at'])]
class ProductAdequacyCheck
{
    use GenerateSlugPrefixedTrait;

    /** Une justification d'un mot ne prouve rien : on exige au moins une phrase. */
    public const int MIN_JUSTIFICATION_LENGTH = 20;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    public private(set) ?Uuid $id = null;

    #[ORM\Column(type: Types::STRING, length: 255, unique: true)]
    public private(set) string $slugId;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: MatchSeverity::class)]
    public private(set) MatchSeverity $worstSeverity;

    /** Empreinte SHA-256 de `content`, calculée à l'enregistrement. */
    #[ORM\Column(type: Types::STRING, length: 64)]
    public private(set) string $contentHash;

    /** Copie dénormalisée du nom de l'auteur : lisible même si le compte disparaît. */
    #[ORM\Column(type: Types::STRING, length: 255)]
    public private(set) string $checkedByName;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    public private(set) \DateTimeImmutable $checkedAt;

    /**
     * @param array{engineVersion: string, input: array<string, mixed>, findings: list<array{criterion: string, severity: string, message: string}>} $content
     */
    private function __construct(
        #[ORM\ManyToOne(targetEntity: Workspace::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public private(set) Workspace $workspace,
        #[ORM\ManyToOne(targetEntity: ComplianceFolder::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public private(set) ComplianceFolder $complianceFolder,
        #[ORM\ManyToOne(targetEntity: ValidatedInvestorProfile::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
        public private(set) ValidatedInvestorProfile $investorProfile,
        #[ORM\ManyToOne(targetEntity: FinancialProduct::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
        public private(set) FinancialProduct $product,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
        public private(set) User $checkedBy,
        #[ORM\Column(type: Types::INTEGER)]
        public private(set) int $amountInCents,
        #[ORM\Column(type: Types::JSON)]
        public private(set) array $content,
        #[ORM\Column(type: Types::TEXT, nullable: true)]
        public private(set) ?string $justification,
    ) {
        $this->id = Uuid::v7();
        $this->slugId = $this->generate_ulid_prefixed('adq_');
        $this->worstSeverity = AdequacyEvaluation::fromArray($content['findings'])->worstSeverity();
        $this->contentHash = hash('sha256', json_encode($content, \JSON_THROW_ON_ERROR));
        $this->checkedByName = $checkedBy->getFullName();
        $this->checkedAt = now();
    }

    public static function record(
        ComplianceFolder $complianceFolder,
        ValidatedInvestorProfile $investorProfile,
        FinancialProduct $product,
        User $checkedBy,
        AdequacyCheckInput $input,
        AdequacyEvaluation $evaluation,
        ?string $justification,
    ): self {
        if (!$investorProfile->isInForce()) {
            throw new \DomainException('Le profil investisseur de ce client a été révoqué : validez un nouveau profil avant toute vérification.');
        }

        if ($investorProfile->workspace !== $complianceFolder->workspace || $product->workspace !== $complianceFolder->workspace) {
            throw new \DomainException('Le profil investisseur et le produit doivent appartenir au cabinet du dossier.');
        }

        $justification = null !== $justification && '' !== trim($justification) ? trim($justification) : null;

        if ($evaluation->hasBlockingFinding() && mb_strlen((string) $justification) < self::MIN_JUSTIFICATION_LENGTH) {
            throw new \DomainException(sprintf('Au moins un point est bloquant : une justification écrite d\'au moins %d caractères est obligatoire pour enregistrer cette vérification.', self::MIN_JUSTIFICATION_LENGTH));
        }

        return new self(
            workspace: $complianceFolder->workspace,
            complianceFolder: $complianceFolder,
            investorProfile: $investorProfile,
            product: $product,
            checkedBy: $checkedBy,
            amountInCents: $input->amountInCents,
            content: [
                'engineVersion' => SuitabilityMatchEvaluator::VERSION,
                'input' => $input->toArray(),
                'findings' => $evaluation->toArray(),
            ],
            justification: $justification,
        );
    }

    public function evaluation(): AdequacyEvaluation
    {
        return AdequacyEvaluation::fromArray($this->content['findings']);
    }

    public function hasBlockingFinding(): bool
    {
        return MatchSeverity::BLOQUANT === $this->worstSeverity;
    }

    /** Nom du produit tel qu'il était au moment de la vérification. */
    public function productNameAtCheck(): string
    {
        $name = $this->content['input']['product']['name'] ?? $this->product->name;

        return \is_string($name) ? $name : $this->product->name;
    }

    public function matchesStoredHash(): bool
    {
        return hash_equals(
            $this->contentHash,
            hash('sha256', json_encode($this->content, \JSON_THROW_ON_ERROR)),
        );
    }
}
