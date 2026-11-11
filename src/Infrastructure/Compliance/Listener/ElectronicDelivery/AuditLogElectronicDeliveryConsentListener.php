<?php

declare(strict_types=1);

namespace App\Infrastructure\Compliance\Listener\ElectronicDelivery;

use App\Domain\AuditLog\Entity\AuditLog;
use App\Domain\AuditLog\Enum\AuditEventType;
use App\Domain\AuditLog\Repository\AuditLogRepositoryInterface;
use App\Domain\Compliance\Event\ElectronicDeliveryConsentGivenEvent;
use App\Domain\Compliance\Event\ElectronicDeliveryConsentRequestedEvent;
use App\Domain\Compliance\Repository\ComplianceFolderRepositoryInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Webmozart\Assert\Assert;

/**
 * Piste d'audit de l'avenant au DER : la demande du CGP et l'accord du client. Chaque event
 * n'est émis qu'une fois par action (demande bornée à une par 24 h, accord idempotent).
 */
readonly class AuditLogElectronicDeliveryConsentListener
{
    public function __construct(
        private AuditLogRepositoryInterface $auditLogRepository,
        private ComplianceFolderRepositoryInterface $folderRepository,
    ) {
    }

    #[AsEventListener]
    public function onRequested(ElectronicDeliveryConsentRequestedEvent $event): void
    {
        $folder = $this->folderRepository->findOneBySlugId($event->folderSlugId);
        Assert::notNull($folder, 'Dossier introuvable pour la piste d\'audit.');

        $this->auditLogRepository->save(AuditLog::initiate(
            eventName: AuditEventType::ELECTRONIC_DELIVERY_CONSENT_REQUESTED,
            payload: [
                'folder_slug_id' => $event->folderSlugId,
                'actor_name' => $event->requestedByName,
                'actor_type' => 'cgp',
            ],
            workspace: $folder->workspace,
        ));
    }

    #[AsEventListener]
    public function onGiven(ElectronicDeliveryConsentGivenEvent $event): void
    {
        $folder = $this->folderRepository->findOneBySlugId($event->folderSlugId);
        Assert::notNull($folder, 'Dossier introuvable pour la piste d\'audit.');

        $this->auditLogRepository->save(AuditLog::initiate(
            eventName: AuditEventType::ELECTRONIC_DELIVERY_CONSENT_GIVEN,
            payload: [
                'consent_slug_id' => $event->consentSlugId,
                'folder_slug_id' => $event->folderSlugId,
                'client_slug_id' => $event->clientSlugId,
                'statement_version' => $event->statementVersion,
                'actor_type' => 'client',
            ],
            workspace: $folder->workspace,
        ));
    }
}
