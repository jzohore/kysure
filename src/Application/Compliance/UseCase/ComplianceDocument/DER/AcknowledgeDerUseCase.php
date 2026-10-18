<?php

declare(strict_types=1);

namespace App\Application\Compliance\UseCase\ComplianceDocument\DER;

use App\Application\Compliance\DTO\Request\AcknowledgeDerRequest;
use App\Application\Compliance\UseCase\ComplianceFolder\ComplianceFolderShowAssembler;
use App\Application\User\UseCase\Client\ProvisionClientForFolderUseCase;
use App\Domain\Compliance\Entity\ComplianceDocument;
use App\Domain\Compliance\Entity\DerAcknowledgement;
use App\Domain\Compliance\Enum\DocumentType;
use App\Domain\Compliance\Event\DerAcknowledgedEvent;
use App\Domain\Compliance\Event\DocumentsRequestedEvent;
use App\Domain\Compliance\Repository\ComplianceDocumentRepositoryInterface;
use App\Domain\Compliance\Repository\ComplianceFolderRepositoryInterface;
use App\Domain\Compliance\Repository\DerAcknowledgementRepositoryInterface;
use App\Domain\Compliance\Service\DocumentRequirementEngine;
use App\Domain\Compliance\ValueObject\DerStatement;
use App\Domain\Database\TransactionManagerInterface;
use App\Domain\Kyc\Enum\DocumentStatus;
use App\Domain\Port\DocumentStorageInterface;

use function Symfony\Component\Clock\now;

use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Enregistre l'accusé de réception d'un DER par le client, à partir du lien
 * nominatif qu'il a reçu par e-mail (il n'a pas encore de compte).
 *
 * L'accusé déclenche aussi la demande de pièces : socle réglementaire du dossier
 * (créé s'il manque) puis pièces obligatoires demandées au client, dans la même
 * transaction que l'accusé.
 */
readonly class AcknowledgeDerUseCase
{
    public function __construct(
        private ComplianceDocumentRepositoryInterface $documentRepository,
        private DerAcknowledgementRepositoryInterface $acknowledgementRepository,
        private ComplianceFolderRepositoryInterface $folderRepository,
        private ComplianceFolderShowAssembler $folderShowAssembler,
        private DocumentStorageInterface $storage,
        private ProvisionClientForFolderUseCase $provisionClientForFolder,
        private DocumentRequirementEngine $documentRequirementEngine,
        private TransactionManagerInterface $transactionManager,
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    /**
     * @return string slugId de l'accusé (celui créé, ou l'existant si déjà acquitté)
     */
    public function __invoke(AcknowledgeDerRequest $request): string
    {
        $document = $this->documentRepository->findOneByAcknowledgementTokenHash(hash('sha256', $request->token));

        if (!$document instanceof ComplianceDocument) {
            throw new \DomainException('Lien d\'accusé de réception invalide.');
        }

        if (DocumentType::DER !== $document->type) {
            throw new \DomainException('Ce lien ne correspond pas à un DER.');
        }

        if ($document->isAcknowledgementTokenExpired()) {
            throw new \DomainException('Ce lien a expiré. Le cabinet doit vous renvoyer le document.');
        }

        if (DocumentStatus::GENERATED !== $document->status || null === $document->storagePath) {
            throw new \DomainException('Le DER n\'est pas encore disponible.');
        }

        // 🛡️ Un document déjà refusé ne peut pas être acquitté par ce même lien :
        // la correction passe par la régénération d'un nouveau DER (nouveau jeton).
        if ($document->isDerDeclined()) {
            throw new \DomainException('Vous avez déclaré ne pas reconnaître ce document. Contactez votre conseiller.');
        }

        // 🛡️ Idempotence : un rechargement de la page ou un double envoi ne crée
        // qu'un seul accusé. On renvoie celui déjà en vigueur, sans événement.
        $existing = $document->acknowledgementInForce();
        if ($existing instanceof DerAcknowledgement) {
            return $existing->slugId;
        }

        if (!$request->accepted) {
            throw new \DomainException('Vous devez confirmer avoir reçu et pris connaissance du document.');
        }

        $folder = $document->folder;
        $recipientEmail = $this->folderShowAssembler->assemble($folder)->contactEmail ?? '';

        if ('' === $recipientEmail) {
            throw new \DomainException('Adresse e-mail de contact introuvable pour ce dossier. Contactez votre conseiller.');
        }

        // SHA calculé sur les octets réellement servis au client, au moment de l'accusé.
        $pdfSha256 = hash('sha256', $this->storage->getContents($document->storagePath));

        $acknowledgement = DerAcknowledgement::record(
            document: $document,
            pdfSha256: $pdfSha256,
            pdfStoragePath: $document->storagePath,
            declaredName: $request->declaredName,
            recipientEmail: $recipientEmail,
            statement: DerStatement::current(),
            ipAddress: $request->ipAddress,
            userAgent: $request->userAgent,
        );

        $date = now()->format('d/m/y H:i');
        $folder->markAsDerAcknowledged($date);
        $folder->markAsAwaitingClient($date);

        // Idempotent : ne crée que les pièces absentes, ne redemande pas ce qui l'est déjà.
        $this->documentRequirementEngine->generateBaseRequirements($folder);
        $folder->requestMandatoryDocumentsFromClient();

        $this->transactionManager->transactional(function () use ($acknowledgement, $folder): void {
            // Le compte client est une conséquence obligatoire de l'accusé, dans la même transaction.
            $this->provisionClientForFolder->__invoke($folder);
            $this->acknowledgementRepository->save($acknowledgement, flush: false);
            $this->folderRepository->save($folder);
        });

        $documentId = $document->id?->toString();
        if (null === $documentId) {
            throw new \DomainException('Le document accusé n\'a pas d\'identifiant.');
        }

        $this->eventDispatcher->dispatch(new DerAcknowledgedEvent(
            documentId: $documentId,
            folderSlugId: $folder->slugId,
            acknowledgementSlugId: $acknowledgement->slugId,
            declaredName: $acknowledgement->declaredName,
            acknowledgedAt: $acknowledgement->acknowledgedAt,
            pdfSha256: $acknowledgement->pdfSha256,
        ));

        $awaitingTypes = array_map(
            static fn (ComplianceDocument $doc): string => $doc->type->value,
            $folder->documentsAwaitingClient(),
        );
        if ([] !== $awaitingTypes) {
            $this->eventDispatcher->dispatch(new DocumentsRequestedEvent(
                folderSlugId: $folder->slugId,
                documentTypes: $awaitingTypes,
                requestedAt: $acknowledgement->acknowledgedAt,
            ));
        }

        return $acknowledgement->slugId;
    }
}
