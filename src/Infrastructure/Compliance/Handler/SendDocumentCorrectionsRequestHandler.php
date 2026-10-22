<?php

declare(strict_types=1);

namespace App\Infrastructure\Compliance\Handler;

use App\Application\Compliance\UseCase\ComplianceFolder\ComplianceFolderShowAssembler;
use App\Domain\Compliance\Repository\ComplianceFolderRepositoryInterface;
use App\Infrastructure\Compliance\Message\SendDocumentCorrectionsRequestMessage;
use App\Infrastructure\Notification\Email\Compliance\DocumentCorrectionsRequestClientEmail;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler]
readonly class SendDocumentCorrectionsRequestHandler
{
    public function __construct(
        private ComplianceFolderRepositoryInterface $folderRepository,
        private ComplianceFolderShowAssembler $assembler,
        private MailerInterface $mailer,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(SendDocumentCorrectionsRequestMessage $message): void
    {
        $folder = $this->folderRepository->findById(Uuid::fromString($message->folderId));

        // Pièces de CETTE demande, encore refusées (un client très rapide a pu redéposer entre-temps : rien à lui
        // redemander pour celle-là).
        $items = [];
        foreach ($folder->documents as $document) {
            if ($document->isRejectionVisibleToClient() && in_array($document->id?->toString(), $message->documentIds, true)) {
                $items[] = ['label' => $document->type->getLabel(), 'reason' => (string) $document->rejectionReason];
            }
        }

        if ([] === $items) {
            return;
        }

        $view = $this->assembler->assemble($folder);
        if (null === $view->contactEmail) {
            // Sans adresse, réessayer n'y changera rien : on le signale au lieu de boucler.
            $this->logger->error('Demande de corrections : aucune adresse e-mail client sur le dossier.', ['folder' => $folder->slugId]);

            return;
        }

        $this->mailer->send(new DocumentCorrectionsRequestClientEmail(
            email: $view->contactEmail,
            clientName: $view->contactName,
            portalUrl: $message->portalUrl,
            workspaceName: $view->workspaceName,
            items: $items,
        ));
    }
}
