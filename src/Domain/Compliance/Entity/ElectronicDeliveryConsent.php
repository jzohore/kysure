<?php

declare(strict_types=1);

namespace App\Domain\Compliance\Entity;

use App\Domain\Compliance\ValueObject\ElectronicDeliveryConsentStatement;
use App\Domain\User\Entity\Client;
use App\Domain\Workspace\Entity\Workspace;
use App\Infrastructure\Trait\GenerateSlugPrefixedTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;

use function Symfony\Component\Clock\now;

use Symfony\Component\Uid\Uuid;

/**
 * Accord du client pour la remise électronique de ses documents, donné par avenant au DER :
 * pour un client qui a accusé un DER antérieur à l'attestation 2026-11-10, sans toucher au DER
 * déjà accusé (figé) ni au statut du dossier. Ligne en ajout seul : le texte accepté et sa
 * version sont copiés, l'horodatage fait foi.
 */
#[ORM\Entity]
#[ORM\Table(name: 'compliance_electronic_delivery_consents')]
#[ORM\Index(name: 'idx_edc_folder', columns: ['compliance_folder_id', 'consented_at'])]
class ElectronicDeliveryConsent
{
    use GenerateSlugPrefixedTrait;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    public private(set) ?Uuid $id = null;

    #[ORM\Column(type: Types::STRING, length: 255, unique: true)]
    public private(set) string $slugId;

    #[ORM\Column(type: Types::TEXT)]
    public private(set) string $statementText;

    #[ORM\Column(type: Types::STRING, length: 20)]
    public private(set) string $statementVersion;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    public private(set) \DateTimeImmutable $consentedAt;

    private function __construct(
        #[ORM\ManyToOne(targetEntity: Workspace::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public private(set) Workspace $workspace,
        #[ORM\ManyToOne(targetEntity: ComplianceFolder::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public private(set) ComplianceFolder $complianceFolder,
        #[ORM\ManyToOne(targetEntity: Client::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public private(set) Client $client,
        ElectronicDeliveryConsentStatement $statement,
    ) {
        $this->id = Uuid::v7();
        $this->slugId = $this->generate_ulid_prefixed('edc_');
        $this->statementText = $statement->text;
        $this->statementVersion = $statement->version;
        $this->consentedAt = now();
    }

    /**
     * @throws \DomainException si le dossier n'appartient pas à ce client ou est archivé
     */
    public static function give(ComplianceFolder $folder, Client $client, ElectronicDeliveryConsentStatement $statement): self
    {
        if ($folder->client !== $client) {
            throw new \DomainException('Ce dossier n\'appartient pas à ce client.');
        }

        if ($folder->isArchived()) {
            throw new \DomainException('Ce dossier est archivé.');
        }

        return new self($folder->workspace, $folder, $client, $statement);
    }
}
