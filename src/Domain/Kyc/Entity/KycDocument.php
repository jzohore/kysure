<?php

declare(strict_types=1);

namespace App\Domain\Kyc\Entity;

use App\Domain\Common\Attribute\Encrypted;
use App\Domain\Compliance\Enum\DocumentType;
use App\Domain\Kyc\Enum\DocumentStatus;
use App\Infrastructure\Trait\GenerateSlugPrefixedTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'kyc_documents')]
class KycDocument
{
    use GenerateSlugPrefixedTrait;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    public ?Uuid $id = null {
        get => $this->id;
    }

    #[ORM\Column(type: Types::STRING, length: 255, unique: true)]
    public private(set) string $slugId;

    #[ORM\ManyToOne(targetEntity: Stakeholder::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    public private(set) ?Stakeholder $stakeholder = null;

    #[ORM\Column(type: 'string', enumType: DocumentStatus::class)]
    public private(set) DocumentStatus $status = DocumentStatus::PENDING;

    #[ORM\Column(length: 255, nullable: true)]
    public private(set) ?string $storagePath = null;

    #[ORM\Column(type: 'text', nullable: true)]
    public private(set) ?string $rejectionReason = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    public private(set) ?\DateTimeImmutable $expiresAt = null;

    /**
     * @var array<string, mixed>|null
     */
    #[Encrypted]
    #[ORM\Column(type: Types::JSON, nullable: true)]
    public private(set) ?array $ocrData = null;

    private function __construct(#[ORM\ManyToOne(targetEntity: KycFolder::class, inversedBy: 'documents')]
        #[ORM\JoinColumn(nullable: false)]
        public private(set) KycFolder $folder, #[ORM\Column(type: 'string', enumType: DocumentType::class)]
        public private(set) DocumentType $type)
    {
        $this->slugId = $this->generate_ulid_prefixed('kyc_doc_');
    }

    /**
     * 🪄 Named Constructor : Pour un document d'entreprise (ex: KBIS).
     */
    public static function requestForCompany(KycFolder $folder, DocumentType $type): self
    {
        return new self($folder, $type);
    }

    /**
     * 🪄 Named Constructor : Pour un document personnel (ex: CNI).
     */
    public static function requestForStakeholder(KycFolder $folder, Stakeholder $stakeholder, DocumentType $type): self
    {
        $document = new self($folder, $type);
        $document->stakeholder = $stakeholder;

        return $document;
    }

    /**
     * Action métier : Le client a uploadé le fichier.
     */
    public function markAsUploaded(string $storagePath): void
    {
        $this->storagePath = $storagePath;
        $this->status = DocumentStatus::UPLOADED;
        $this->rejectionReason = null; // On nettoie l'ancien refus éventuel
    }

    public function markAsProcessed(): void
    {
        $this->status = DocumentStatus::VALID;
        $this->rejectionReason = null;
    }

    /**
     * Action métier : L'avocat refuse le document.
     */
    public function reject(string $reason): void
    {
        $this->status = DocumentStatus::REJECTED;
        $this->rejectionReason = $reason;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function setExtractedData(array $data): void
    {
        $this->ocrData = $data;
    }
}
