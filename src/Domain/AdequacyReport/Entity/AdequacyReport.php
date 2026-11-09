<?php

declare(strict_types=1);

namespace App\Domain\AdequacyReport\Entity;

use App\Domain\ClientFile\Enum\InvestmentHorizon;
use App\Domain\ClientFile\Enum\InvestmentObjectiveType;
use App\Domain\ClientFile\Enum\ObjectivePriority;
use App\Domain\Compliance\Entity\ComplianceFolder;
use App\Domain\ProductCatalogue\Enum\SfdrClassification;
use App\Domain\Suitability\Entity\ValidatedInvestorProfile;
use App\Domain\Suitability\Enum\InvestorProfileLevel;
use App\Domain\Suitability\Enum\ProductFamily;
use App\Domain\User\Entity\Client;
use App\Domain\User\Entity\User;
use App\Domain\Workspace\Entity\Workspace;
use App\Infrastructure\Trait\GenerateSlugPrefixedTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;

use function Symfony\Component\Clock\now;

use Symfony\Component\Uid\Uuid;
use Webmozart\Assert\Assert;

/**
 * Rapport d'adéquation figé (lot 5, issue #32) : le livrable remis au client, qui remplace le
 * rapport Word du CGP (art. 54 §12 du règlement délégué 2017/565).
 *
 * Copie exacte du pattern {@see ValidatedInvestorProfile} : la ligne n'existe qu'à partir de la
 * validation par le CGP, elle COPIE tout ce qu'elle affiche (cabinet, situation du client,
 * profil investisseur, vérifications citées avec leurs constats et leurs frais, justification
 * du conseil) et scelle le tout par une empreinte SHA-256. Aucune jointure n'est relue pour
 * l'affichage : un rapport archivé reste identique même si le produit, la fiche patrimoniale
 * ou le cabinet changent ensuite.
 *
 * Une seule version en vigueur par dossier (garde portée par le use case). Pour remplacer un
 * rapport : le révoquer (motif obligatoire), puis en valider une nouvelle version.
 *
 * Le profil investisseur cité doit être en vigueur à la validation. S'il est révoqué ensuite,
 * le rapport n'est ni modifié ni supprimé : il est signalé ({@see self::isBasedOnRevokedProfile()}).
 */
#[ORM\Entity]
#[ORM\Table(name: 'adequacy_reports')]
#[ORM\UniqueConstraint(name: 'uniq_adequacy_report_folder_version', columns: ['compliance_folder_id', 'version'])]
#[ORM\Index(name: 'idx_adequacy_report_profile', columns: ['investor_profile_id'])]
class AdequacyReport
{
    use GenerateSlugPrefixedTrait;

    /** Version du format de `content` : le gabarit PDF saura relire les anciens formats. */
    public const int FORMAT_VERSION = 1;

    /** Le rapport porte l'exposition juridique du CGP : la justification doit être rédigée. */
    public const int MIN_RATIONALE_LENGTH = 50;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    public private(set) ?Uuid $id = null;

    #[ORM\Column(type: Types::STRING, length: 255, unique: true)]
    public private(set) string $slugId;

    /** Empreinte SHA-256 de `content`, calculée à la validation. */
    #[ORM\Column(type: Types::STRING, length: 64)]
    public private(set) string $contentHash;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    public private(set) \DateTimeImmutable $validatedAt;

    /** Copie dénormalisée du nom du valideur : lisible même si le compte disparaît. */
    #[ORM\Column(type: Types::STRING, length: 255)]
    public private(set) string $validatedByName;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public private(set) ?\DateTimeImmutable $revokedAt = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    public private(set) ?string $revokedByName = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public private(set) ?string $revokeReason = null;

    /**
     * Chemin du PDF, généré de façon asynchrone après validation — voir
     * {@see \App\Infrastructure\AdequacyReport\Handler\GenerateAdequacyReportPdfHandler}.
     * `null` tant que la génération n'a pas abouti.
     */
    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    public private(set) ?string $pdfStoragePath = null;

    /**
     * @param array<string, mixed> $content contenu figé, voir {@see self::composeContent()}
     */
    private function __construct(
        #[ORM\ManyToOne(targetEntity: Workspace::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public private(set) Workspace $workspace,
        #[ORM\ManyToOne(targetEntity: ComplianceFolder::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public private(set) ComplianceFolder $complianceFolder,
        /** Destinataire du rapport : c'est lui (et lui seul) qui peut le récupérer depuis son espace. */
        #[ORM\ManyToOne(targetEntity: Client::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public private(set) Client $client,
        #[ORM\ManyToOne(targetEntity: ValidatedInvestorProfile::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
        public private(set) ValidatedInvestorProfile $investorProfile,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
        public private(set) User $validatedBy,
        #[ORM\Column(type: Types::JSON)]
        public private(set) array $content,
        /** Version incrémentale par dossier : au plus une version en vigueur à la fois. */
        #[ORM\Column(type: Types::INTEGER)]
        public private(set) int $version,
    ) {
        $this->id = Uuid::v7();
        $this->slugId = $this->generate_ulid_prefixed('adr_');
        $this->contentHash = $this->hashContent($content);
        $this->validatedAt = now();
        $this->validatedByName = $validatedBy->getFullName();
    }

    /**
     * Valide et fige le rapport. Mêmes règles que le brouillon ({@see self::composeContent()}),
     * plus une justification du conseil rédigée.
     *
     * @param list<ProductAdequacyCheck> $checks
     * @param array<string, mixed>       $cabinet         identité du cabinet au moment de la validation
     * @param array<string, mixed>       $clientSituation situation du client (fiche patrimoniale) au moment de la validation
     *
     * @throws \DomainException si une règle métier n'est pas respectée
     */
    public static function validate(
        ComplianceFolder $folder,
        ValidatedInvestorProfile $investorProfile,
        array $checks,
        User $validatedBy,
        string $advisorRationale,
        array $cabinet,
        array $clientSituation,
        int $version,
    ): self {
        $advisorRationale = trim($advisorRationale);
        if (mb_strlen($advisorRationale) < self::MIN_RATIONALE_LENGTH) {
            throw new \DomainException(sprintf('La justification du conseil doit expliquer en quoi la recommandation répond à la situation du client (%d caractères minimum).', self::MIN_RATIONALE_LENGTH));
        }

        if ($version < 1) {
            throw new \DomainException('Numéro de version de rapport invalide.');
        }

        $content = self::composeContent($folder, $investorProfile, $checks, $advisorRationale, $cabinet, $clientSituation);
        $client = $folder->client;
        Assert::isInstanceOf($client, Client::class); // garanti par composeContent()

        return new self(
            workspace: $folder->workspace,
            complianceFolder: $folder,
            client: $client,
            investorProfile: $investorProfile,
            validatedBy: $validatedBy,
            content: $content,
            version: $version,
        );
    }

    /**
     * Assemble le contenu du rapport, sans rien persister : c'est aussi ce qu'affiche le
     * brouillon, pour que l'aperçu soit exactement ce qui sera figé.
     *
     * Règles : dossier actif avec un client, profil investisseur en vigueur du même cabinet et
     * du même client, au moins une vérification, toutes du dossier, toutes faites sur CE profil
     * (une vérification sur une version antérieure du profil ne peut pas fonder le rapport) et
     * intactes (empreinte vérifiée).
     *
     * @param list<ProductAdequacyCheck> $checks
     * @param array<string, mixed>       $cabinet
     * @param array<string, mixed>       $clientSituation
     *
     * @return array<string, mixed>
     *
     * @throws \DomainException si une règle métier n'est pas respectée
     */
    public static function composeContent(
        ComplianceFolder $folder,
        ValidatedInvestorProfile $investorProfile,
        array $checks,
        string $advisorRationale,
        array $cabinet,
        array $clientSituation,
    ): array {
        if ($folder->isArchived()) {
            throw new \DomainException('Ce dossier est archivé : aucun rapport d\'adéquation ne peut y être émis.');
        }

        $client = $folder->client;
        if (!$client instanceof Client) {
            throw new \DomainException('Le dossier n\'a pas encore de client : impossible d\'émettre un rapport d\'adéquation.');
        }

        if (!$investorProfile->isInForce()) {
            throw new \DomainException('Le profil investisseur de ce client a été révoqué : validez un nouveau profil, puis refaites les vérifications.');
        }

        if ($investorProfile->workspace !== $folder->workspace || $investorProfile->client !== $client) {
            throw new \DomainException('Le profil investisseur doit être celui du client du dossier, dans ce cabinet.');
        }

        if ([] === $checks) {
            throw new \DomainException('Sélectionnez au moins une vérification d\'adéquation à citer dans le rapport.');
        }

        $seen = [];
        foreach ($checks as $check) {
            if (isset($seen[$check->slugId])) {
                throw new \DomainException('Une même vérification ne peut être citée qu\'une fois.');
            }

            $seen[$check->slugId] = true;

            if ($check->complianceFolder !== $folder) {
                throw new \DomainException('Une vérification citée n\'appartient pas à ce dossier.');
            }

            if ($check->investorProfile !== $investorProfile) {
                throw new \DomainException(sprintf('La vérification « %s » a été faite sur une version antérieure du profil investisseur : refaites-la sur le profil en vigueur.', $check->productNameAtCheck()));
            }

            if (!$check->matchesStoredHash()) {
                throw new \DomainException(sprintf('L\'intégrité de la vérification « %s » n\'est plus garantie (empreinte invalide) : elle ne peut pas être citée.', $check->productNameAtCheck()));
            }
        }

        return [
            'formatVersion' => self::FORMAT_VERSION,
            'folderReference' => $folder->reference,
            'cabinet' => $cabinet,
            'client' => ['fullName' => $client->getFullName(), ...$clientSituation],
            'investorProfile' => self::profileSnapshot($investorProfile),
            'checks' => array_map(self::checkSnapshot(...), $checks),
            'advisorRationale' => trim($advisorRationale),
        ];
    }

    /**
     * Révoque le rapport : il reste consultable et archivé (y compris par le client), mais
     * n'est plus en vigueur. Action irréversible, motif obligatoire.
     */
    public function revoke(User $revokedBy, string $reason): void
    {
        if ($this->revokedAt instanceof \DateTimeImmutable) {
            throw new \DomainException('Ce rapport d\'adéquation a déjà été révoqué.');
        }

        $reason = trim($reason);
        if ('' === $reason) {
            throw new \DomainException('Un motif est obligatoire pour révoquer un rapport d\'adéquation.');
        }

        $this->revokedAt = now();
        $this->revokedByName = $revokedBy->getFullName();
        $this->revokeReason = $reason;
    }

    public function isInForce(): bool
    {
        return !$this->revokedAt instanceof \DateTimeImmutable;
    }

    public function isRevoked(): bool
    {
        return $this->revokedAt instanceof \DateTimeImmutable;
    }

    /**
     * Le profil investisseur cité a été révoqué après la validation du rapport : le rapport
     * n'est pas modifié, mais il est signalé (à refaire sur le nouveau profil).
     */
    public function isBasedOnRevokedProfile(): bool
    {
        return $this->investorProfile->isRevoked();
    }

    public function markPdfGenerated(string $storagePath): void
    {
        $this->pdfStoragePath = $storagePath;
    }

    public function isPdfAvailable(): bool
    {
        return null !== $this->pdfStoragePath;
    }

    public function checkCount(): int
    {
        $checks = $this->content['checks'] ?? [];

        return \is_array($checks) ? \count($checks) : 0;
    }

    public function matchesStoredHash(): bool
    {
        return hash_equals($this->contentHash, $this->hashContent($this->content));
    }

    /**
     * @param array<string, mixed> $content
     */
    private function hashContent(array $content): string
    {
        return hash('sha256', json_encode($content, \JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    private static function profileSnapshot(ValidatedInvestorProfile $profile): array
    {
        $retained = InvestorProfileLevel::tryFrom($profile->retainedProfileLevel());

        return [
            'slugId' => $profile->slugId,
            'version' => $profile->version,
            'retainedLevel' => $profile->retainedProfileLevel(),
            'retainedLabel' => $retained?->getLabel(),
            'computedLevel' => $profile->computedProfileLevel(),
            'overridden' => $profile->isOverridden(),
            'overrideReason' => $profile->overrideReason,
            'validatedAt' => $profile->validatedAt->format(\DateTimeInterface::ATOM),
            'validatedByName' => $profile->validatedByName,
            'contentHash' => $profile->contentHash,
        ];
    }

    /**
     * Une vérification citée : son contenu figé est recopié À L'IDENTIQUE (même empreinte que
     * la source, frais compris — absents pour une vérification antérieure au lot 4), plus un
     * résumé déjà libellé pour l'affichage, indépendant des enums du code.
     *
     * @return array<string, mixed>
     */
    private static function checkSnapshot(ProductAdequacyCheck $check): array
    {
        $input = $check->content['input'];
        /** @var array<string, mixed> $product */
        $product = \is_array($input['product'] ?? null) ? $input['product'] : [];
        /** @var array<string, mixed> $objective */
        $objective = \is_array($input['objective'] ?? null) ? $input['objective'] : [];

        $family = ProductFamily::tryFrom(self::string($product['family'] ?? null));
        $sfdr = SfdrClassification::tryFrom(self::string($product['sfdrClassification'] ?? null));
        $objectiveType = InvestmentObjectiveType::tryFrom(self::string($objective['type'] ?? null));
        $horizon = InvestmentHorizon::tryFrom(self::string($objective['horizon'] ?? null));
        $priority = ObjectivePriority::tryFrom(self::string($objective['priority'] ?? null));

        $findings = [];
        foreach ($check->evaluation()->findings as $finding) {
            $findings[] = [
                'criterion' => $finding->criterion->getLabel(),
                'severity' => $finding->severity->value,
                'severityLabel' => $finding->severity->getLabel(),
                'message' => $finding->message,
            ];
        }

        return [
            'slugId' => $check->slugId,
            'contentHash' => $check->contentHash,
            'checkedAt' => $check->checkedAt->format(\DateTimeInterface::ATOM),
            'checkedByName' => $check->checkedByName,
            'amountInCents' => $check->amountInCents,
            'worstSeverity' => $check->worstSeverity->value,
            'worstSeverityLabel' => $check->worstSeverity->getLabel(),
            'justification' => $check->justification,
            'summary' => [
                'productName' => $check->productNameAtCheck(),
                'isin' => $product['isin'] ?? null,
                'familyLabel' => $family?->getLabel(),
                'sriLevel' => $product['sriLevel'] ?? null,
                'minimumHorizonYears' => $product['minimumHorizonYears'] ?? null,
                'annualFeesBasisPoints' => $product['annualFeesBasisPoints'] ?? null,
                'sfdrLabel' => $sfdr?->getLabel(),
                'objectiveLabel' => $objectiveType?->getLabel(),
                'objectiveHorizonLabel' => $horizon?->getLabel(),
                'objectivePriorityLabel' => $priority?->getLabel(),
                'findings' => $findings,
            ],
            'content' => $check->content,
        ];
    }

    private static function string(mixed $value): string
    {
        return \is_string($value) ? $value : (\is_int($value) ? (string) $value : '');
    }
}
