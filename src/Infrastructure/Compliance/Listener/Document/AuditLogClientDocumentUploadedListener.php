<?php

declare(strict_types=1);

namespace App\Infrastructure\Compliance\Listener\Document;

use App\Domain\AuditLog\Entity\AuditLog;
use App\Domain\AuditLog\Enum\AuditEventType;
use App\Domain\AuditLog\Repository\AuditLogRepositoryInterface;
use App\Domain\Compliance\Event\ClientDocumentUploadedEvent;
use App\Domain\Compliance\Repository\ComplianceFolderRepositoryInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Webmozart\Assert\Assert;

/**
 * Trace inaltérable du dépôt d'une pièce par le client. Aucune donnée
 * effaçable (nom de fichier, IP, user-agent) : le journal d'audit ne se purge
 * pas. La preuve du contenu est l'empreinte SHA-256 du fichier reçu.
 */
#[AsEventListener]
readonly class AuditLogClientDocumentUploadedListener
{
    public function __construct(
        private AuditLogRepositoryInterface $auditLogRepository,
        private ComplianceFolderRepositoryInterface $folderRepository,
    ) {
    }

    public function __invoke(ClientDocumentUploadedEvent $event): void
    {
        $folder = $this->folderRepository->findOneBySlugId($event->getFolderSlugId());
        Assert::notNull($folder, 'Le dossier lié au dépôt de pièce est introuvable.');

        $workspace = $folder->workspace;
        Assert::notNull($workspace);

        $audit = AuditLog::initiate(
            eventName: AuditEventType::KYC_DOCUMENT_UPLOADED,
            payload: [
                'document_id' => $event->getDocumentId(),
                'document_type' => $event->getDocumentType(),
                'folder_slug_id' => $folder->slugId,
                'document_reference' => $folder->reference ?? 'N/A',
                'workspace_name' => $workspace->name,
                'actor_type' => 'client',
                'mime_type' => $event->getMimeType(),
                'size' => $event->getSize(),
                'file_sha256' => $event->getSha256(),
                'uploaded_at' => $event->getUploadedAt()->format(\DateTimeInterface::ATOM),
            ],
            workspace: $workspace,
        );

        $this->auditLogRepository->save($audit);
    }
}
