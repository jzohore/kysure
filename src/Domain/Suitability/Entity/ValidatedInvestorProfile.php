<?php

declare(strict_types=1);

namespace App\Domain\Suitability\Entity;

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
 * Instantané figé du profil investisseur d'un client, tel que validé par le CGP responsable.
 *
 * Contrairement à {@see InvestorProfileAssessment} (qui peut faire l'objet d'une nouvelle
 * tentative après correction), cette entité stocke une COPIE gelée des réponses et du résultat
 * du moteur de scoring au moment de la validation : elle ne bouge plus, même si l'assessment
 * source évolue par la suite. Pattern calqué sur {@see \App\Domain\Compliance\Entity\ValidatedMeetingReport}.
 *
 * La ligne n'existe qu'à partir de la validation : `validatedAt` / `validatedByName` sont donc
 * toujours renseignés. `revokedAt` ne l'est que si le profil est révoqué par la suite.
 */
#[ORM\Entity]
#[ORM\Table(name: 'suitability_validated_investor_profiles')]
#[ORM\UniqueConstraint(name: 'uniq_client_workspace_profile_version', columns: ['client_id', 'workspace_id', 'version'])]
class ValidatedInvestorProfile
{
    use GenerateSlugPrefixedTrait;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    public private(set) ?Uuid $id = null;

    #[ORM\Column(type: Types::STRING, length: 255, unique: true)]
    public private(set) string $slugId;

    /**
     * Empreinte SHA-256 de `content` au moment de la validation : permet de prouver que le
     * contenu affiché n'a pas été altéré.
     */
    #[ORM\Column(type: Types::STRING, length: 64)]
    public private(set) string $contentHash;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    public private(set) \DateTimeImmutable $validatedAt;

    /**
     * Copie dénormalisée du nom du valideur : l'instantané doit rester lisible même si le
     * compte utilisateur est supprimé plus tard.
     */
    #[ORM\Column(type: Types::STRING, length: 255)]
    public private(set) string $validatedByName;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public private(set) ?\DateTimeImmutable $revokedAt = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    public private(set) ?string $revokedByName = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public private(set) ?string $revokeReason = null;

    /**
     * Correction du CGP sur le profil calculé par le moteur (1 à 7), le cas échéant. Distinct
     * du profil calculé : {@see self::retainedProfileLevel()} tranche laquelle fait foi, sans
     * jamais perdre la proposition initiale de l'algorithme (audit conformité : « distinction
     * computedProfile / retainedProfile »).
     */
    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    public private(set) ?int $overriddenProfileLevel = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public private(set) ?string $overrideReason = null;

    /**
     * Chemin de stockage du PDF de synthèse (déclaration d'adéquation), généré de façon
     * asynchrone après validation — voir {@see \App\Infrastructure\Suitability\Handler\GenerateInvestorProfilePdfHandler}.
     * `null` tant que la génération n'a pas encore abouti.
     */
    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    public private(set) ?string $pdfStoragePath = null;

    /**
     * @param array{answers: array<string, mixed>, scoreSnapshot: array<string, mixed>} $content copie figée des réponses et du résultat du moteur de scoring (voir {@see InvestorProfileAssessment::answersAsMap()} et {@see \App\Domain\Suitability\ValueObject\SuitabilityScoreResult::toArray()})
     */
    private function __construct(
        #[ORM\ManyToOne(targetEntity: Workspace::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public private(set) Workspace $workspace,
        #[ORM\ManyToOne(targetEntity: Client::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public private(set) Client $client,
        #[ORM\ManyToOne(targetEntity: InvestorProfileAssessment::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
        public private(set) InvestorProfileAssessment $assessment,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
        public private(set) User $validatedBy,
        #[ORM\Column(type: Types::JSON)]
        public private(set) array $content,
        /**
         * Version incrémentale par (client, cabinet) : un même client peut être suivi par
         * plusieurs cabinets ({@see Client::$workspaces}), chacun avec
         * son propre historique de validation, totalement indépendant des autres — un CGP ne
         * doit jamais voir ni influencer le profil validé par un cabinet concurrent. Au plus
         * une version « en vigueur » (non révoquée) à la fois, par cabinet.
         */
        #[ORM\Column(type: Types::INTEGER, options: ['default' => 1])]
        public private(set) int $version,
    ) {
        $this->id = Uuid::v7();
        $this->slugId = $this->generate_ulid_prefixed('vip_');
        $this->contentHash = hash('sha256', json_encode($this->content, \JSON_THROW_ON_ERROR));
        $this->validatedAt = now();
        $this->validatedByName = $validatedBy->getFullName();
    }

    /**
     * @param array{answers: array<string, mixed>, scoreSnapshot: array<string, mixed>} $content
     */
    public static function validate(
        Workspace $workspace,
        Client $client,
        InvestorProfileAssessment $assessment,
        User $validatedBy,
        array $content,
        int $version,
        ?int $overriddenProfileLevel = null,
        ?string $overrideReason = null,
    ): self {
        if (null !== $overriddenProfileLevel) {
            Assert::range($overriddenProfileLevel, 1, 7, 'Le profil corrigé doit être compris entre 1 et 7.');
            Assert::stringNotEmpty(trim((string) $overrideReason), 'Un motif est obligatoire pour corriger le profil calculé.');
        }

        $profile = new self($workspace, $client, $assessment, $validatedBy, $content, $version);
        $profile->overriddenProfileLevel = $overriddenProfileLevel;
        $profile->overrideReason = null !== $overriddenProfileLevel ? trim((string) $overrideReason) : null;

        return $profile;
    }

    /**
     * Révoque le profil : il reste consultable et archivé, mais n'est plus en vigueur
     * (remplacé par une nouvelle version). Action irréversible.
     */
    public function revoke(User $revokedBy, string $reason): void
    {
        if ($this->revokedAt instanceof \DateTimeImmutable) {
            throw new \DomainException('Ce profil investisseur a déjà été révoqué.');
        }

        $reason = trim($reason);
        if ('' === $reason) {
            throw new \DomainException('Un motif est obligatoire pour révoquer un profil investisseur validé.');
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

    public function isOverridden(): bool
    {
        return null !== $this->overriddenProfileLevel;
    }

    public function markPdfGenerated(string $storagePath): void
    {
        $this->pdfStoragePath = $storagePath;
    }

    /**
     * Le profil qui fait foi : la correction du CGP si elle existe, sinon le profil calculé
     * par le moteur de scoring. Ne jamais confondre les deux dans l'affichage : voir
     * {@see self::computedProfileLevel()} pour la proposition initiale de l'algorithme.
     */
    public function retainedProfileLevel(): int
    {
        return $this->overriddenProfileLevel ?? $this->computedProfileLevel();
    }

    public function computedProfileLevel(): int
    {
        /** @var int $level */
        $level = $this->content['scoreSnapshot']['finalProfile'];

        return $level;
    }

    /**
     * Vérifie l'intégrité de l'instantané stocké.
     */
    public function matchesStoredHash(): bool
    {
        return hash_equals(
            $this->contentHash,
            hash('sha256', json_encode($this->content, \JSON_THROW_ON_ERROR)),
        );
    }
}
