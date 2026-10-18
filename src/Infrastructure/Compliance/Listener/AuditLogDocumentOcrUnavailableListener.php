<?php

declare(strict_types=1);

namespace App\Infrastructure\Compliance\Listener;

use App\Domain\AuditLog\Entity\AuditLog;
use App\Domain\AuditLog\Enum\AuditEventType;
use App\Domain\AuditLog\Repository\AuditLogRepositoryInterface;
use App\Domain\Compliance\Event\DocumentOcrUnavailableEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Webmozart\Assert\Assert;

/**
 * Trace qu'une pièce n'a pas pu être analysée automatiquement (retries épuisés) : le dossier
 * prouve ainsi que le contrôle manuel a pris le relais. Aucune donnée extraite dans le payload.
 */
#[AsEventListener]
readonly class AuditLogDocumentOcrUnavailableListener
{
    public function __construct(
        private AuditLogRepositoryInterface $auditLogRepository,
    ) {
    }

    public function __invoke(DocumentOcrUnavailableEvent $event): void
    {
        $document = $event->document;
        $folder = $document->folder;
        $workspace = $folder->workspace;

        Assert::notNull($document->id);

        $audit = AuditLog::initiate(
            eventName: AuditEventType::KYC_DOCUMENT_OCR_UNAVAILABLE,
            payload: [
                'document_id' => $document->id->toString(),
                'document_type' => $document->type->value,
                'folder_slug_id' => $folder->slugId,
                'workspace_name' => $workspace->name,
                'actor_type' => 'system_ocr',
                'unavailable_at' => $document->ocrUnavailableAt?->format(\DateTimeInterface::ATOM),
            ],
            workspace: $workspace,
        );

        $this->auditLogRepository->save($audit);
    }
}
