<?php

declare(strict_types=1);

namespace App\Application\Compliance\UseCase\ElectronicDelivery;

use App\Domain\Compliance\Entity\ComplianceDocument;
use App\Domain\Compliance\Entity\ComplianceFolder;
use App\Domain\Compliance\Entity\DerAcknowledgement;
use App\Domain\Compliance\Entity\ElectronicDeliveryConsent;
use App\Domain\Compliance\Repository\ComplianceDocumentRepositoryInterface;
use App\Domain\Compliance\Repository\ElectronicDeliveryConsentRepositoryInterface;
use App\Domain\Compliance\ValueObject\DerStatement;

/**
 * Où trouver l'accord du client pour la remise électronique (art. 3 du règlement délégué
 * 2017/565) : dans l'accusé du DER s'il porte l'attestation 2026-11-10 ou suivante, sinon dans
 * un avenant au DER ({@see ElectronicDeliveryConsent}). Source unique pour le rapport
 * d'adéquation, la checklist du dossier et l'espace client.
 */
final readonly class ElectronicDeliveryConsentResolver
{
    public const string SOURCE_DER = 'der';
    public const string SOURCE_AMENDMENT = 'avenant';

    public function __construct(
        private ComplianceDocumentRepositoryInterface $documentRepository,
        private ElectronicDeliveryConsentRepositoryInterface $consentRepository,
    ) {
    }

    /**
     * Preuve de l'accord, à figer dans un rapport — `null` si le client ne l'a pas donné.
     *
     * @return array{acknowledgementSlugId: string, acknowledgedAt: string, statementVersion: string, source: string}|null
     */
    public function resolve(ComplianceFolder $folder): ?array
    {
        $acknowledgement = $this->derAcknowledgement($folder);
        if ($acknowledgement instanceof DerAcknowledgement && DerStatement::coversElectronicDelivery($acknowledgement->statementVersion)) {
            return [
                'acknowledgementSlugId' => $acknowledgement->slugId,
                'acknowledgedAt' => $acknowledgement->acknowledgedAt->format(\DateTimeInterface::ATOM),
                'statementVersion' => $acknowledgement->statementVersion,
                'source' => self::SOURCE_DER,
            ];
        }

        $consent = $this->consentRepository->findLatestByFolder($folder);
        if ($consent instanceof ElectronicDeliveryConsent) {
            return [
                'acknowledgementSlugId' => $consent->slugId,
                'acknowledgedAt' => $consent->consentedAt->format(\DateTimeInterface::ATOM),
                'statementVersion' => $consent->statementVersion,
                'source' => self::SOURCE_AMENDMENT,
            ];
        }

        return null;
    }

    /**
     * Le client a accusé un DER, mais sur une ancienne attestation, et n'a pas encore signé
     * l'avenant : c'est le seul cas où l'avenant doit lui être demandé.
     */
    public function needsAmendment(ComplianceFolder $folder): bool
    {
        return $this->derAcknowledgement($folder) instanceof DerAcknowledgement && null === $this->resolve($folder);
    }

    public function derAcknowledgement(ComplianceFolder $folder): ?DerAcknowledgement
    {
        $der = $this->documentRepository->findDerByFolder($folder);

        return $der instanceof ComplianceDocument ? $der->acknowledgementInForce() : null;
    }
}
