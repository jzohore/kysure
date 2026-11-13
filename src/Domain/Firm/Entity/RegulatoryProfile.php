<?php

declare(strict_types=1);

namespace App\Domain\Firm\Entity;

use App\Domain\Workspace\Entity\Workspace;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;

use function Symfony\Component\Clock\now;

use Symfony\Component\Uid\Uuid;
use Webmozart\Assert\Assert;

#[ORM\Entity]
#[ORM\Table(name: 'regulatory_profiles')]
class RegulatoryProfile
{
    /** Environ deux pages de DER : au-delà, le document perd sa lisibilité pour le client. */
    public const int DER_ADDITIONAL_INFORMATION_MAX_LENGTH = 5000;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    public private(set) ?Uuid $id = null;

    // --- DONNÉES PUREMENT AMF / DER ---

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    public private(set) ?string $logoStoragePath = null;

    #[ORM\Column(length: 255, nullable: true)]
    public private(set) ?string $filename = null;

    #[ORM\Column(length: 255, nullable: true)]
    public private(set) ?string $mimeType = null;

    #[ORM\Column(nullable: true)]
    public private(set) ?int $size = null;

    #[ORM\Column(type: Types::STRING, length: 20, nullable: true)]
    public private(set) ?string $oriasNumber = null;

    #[ORM\Column(type: Types::STRING, length: 100, nullable: true)]
    public private(set) ?string $professionalAssociation = null; // CNCGP, ANACOFI...

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    public private(set) ?string $rcProInsurer = null;

    #[ORM\Column(type: Types::STRING, length: 100, nullable: true)]
    public private(set) ?string $rcProPolicyNumber = null;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    public private(set) bool $isIndependent = true;

    /** Nom du conseiller signataire, imprimé sur le DER (« Pour le cabinet »). */
    #[ORM\Column(type: Types::STRING, length: 150, nullable: true)]
    public private(set) ?string $signatoryName = null;

    /** Ville portée par la mention « Fait à … » du DER. */
    #[ORM\Column(type: Types::STRING, length: 120, nullable: true)]
    public private(set) ?string $signatoryCity = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public private(set) ?string $signatureBase64 = null;

    /**
     * Précisions propres au cabinet imprimées dans une section dédiée du DER : services fournis,
     * modalités de rémunération, gestion des conflits d'intérêts. Texte brut multiligne (jamais de
     * HTML) : il est échappé puis mis en forme par `nl2br` dans le gabarit PDF.
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public private(set) ?string $derAdditionalInformation = null;

    /**
     * @var array<int, array{name: string|null, address: string|null, email: string|null, phone: string|null}>
     */
    #[ORM\Column(type: Types::JSON)]
    public private(set) array $partners = [];

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    public private(set) bool $isValidOrias = true;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true, options: ['default' => null])]
    public private(set) ?\DateTimeImmutable $lastCheckOrias = null;

    public function __construct(
        #[ORM\OneToOne(targetEntity: Workspace::class, inversedBy: 'regulatoryProfile')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public private(set) Workspace $workspace,
    ) {
    }

    public static function initiate(Workspace $workspace): self
    {
        return new self($workspace);
    }

    /**
     * @param array<int, array{name: string|null, address: string|null, email: string|null, phone: string|null}> $partners
     */
    public function update(
        ?string $oriasNumber,
        ?string $professionalAssociation,
        ?string $rcProInsurer,
        ?string $rcProPolicyNumber,
        bool $isIndependent,
        array $partners,
        ?string $signatoryName = null,
        ?string $signatoryCity = null,
        ?string $derAdditionalInformation = null,
    ): void {
        // Fins de ligne normalisées : un texte collé depuis Word arrive en \r\n.
        $derAdditionalInformation = null !== $derAdditionalInformation
            ? trim(str_replace(["\r\n", "\r"], "\n", $derAdditionalInformation)) ?: null
            : null;
        Assert::nullOrMaxLength(
            $derAdditionalInformation,
            self::DER_ADDITIONAL_INFORMATION_MAX_LENGTH,
            'Les précisions du DER ne peuvent dépasser %2$s caractères.',
        );

        $this->oriasNumber = $oriasNumber;
        $this->professionalAssociation = $professionalAssociation;
        $this->rcProInsurer = $rcProInsurer;
        $this->rcProPolicyNumber = $rcProPolicyNumber;
        $this->isIndependent = $isIndependent;
        $this->partners = $partners;
        $this->signatoryName = null !== $signatoryName ? trim($signatoryName) ?: null : null;
        $this->signatoryCity = null !== $signatoryCity ? trim($signatoryCity) ?: null : null;
        $this->derAdditionalInformation = $derAdditionalInformation;
        $this->lastCheckOrias = now();
    }

    public function updateStoragePath(string $storagePath, string $filename): void
    {
        $this->logoStoragePath = $storagePath;
        $this->filename = $filename;
    }

    public function updateSignature(string $signatureBase64): void
    {
        $this->signatureBase64 = $signatureBase64;
    }

    public function updateOriasStatus(bool $isValidOrias, \DateTimeImmutable $checkedAt): void
    {
        $this->isValidOrias = $isValidOrias;
        $this->lastCheckOrias = $checkedAt;
    }

    public function isProfileValid(): bool
    {
        return !in_array($this->oriasNumber, [null, '', '0'], true)
            && !in_array($this->professionalAssociation, [null, '', '0'], true) // ex: ANACOFI, CNCGP...
            && !in_array($this->rcProInsurer, [null, '', '0'], true)            // Compagnie d'assurance
            && !in_array($this->rcProPolicyNumber, [null, '', '0'], true);      // Numéro de police
    }
}
