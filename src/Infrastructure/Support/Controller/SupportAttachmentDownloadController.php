<?php

declare(strict_types=1);

namespace App\Infrastructure\Support\Controller;

use App\Domain\Port\DocumentStorageInterface;
use App\Domain\Support\Entity\SupportMessage;
use App\Domain\Support\Entity\SupportThread;
use App\Infrastructure\Support\Voter\SupportThreadVoter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Webmozart\Assert\Assert;

/**
 * Redirige vers une URL S3 signée et temporaire pour une pièce jointe de message support.
 * La voter garantit que seul le cabinet propriétaire du ticket (ou un super-admin) y accède.
 */
#[AsController]
#[Route(path: '/app/billing/support/{slugId}/attachments/{messageSlugId}', name: 'app_support_attachment_download', methods: ['GET'])]
#[Route(path: '/admin/support/{slugId}/attachments/{messageSlugId}', name: 'admin_support_attachment_download', methods: ['GET'])]
readonly class SupportAttachmentDownloadController
{
    public function __construct(
        private DocumentStorageInterface $documentStorage,
    ) {
    }

    #[IsGranted(SupportThreadVoter::VIEW, subject: 'thread')]
    public function __invoke(
        #[MapEntity(mapping: ['slugId' => 'slugId'])]
        SupportThread $thread,
        string $messageSlugId,
    ): RedirectResponse {
        $message = null;
        foreach ($thread->messages as $candidate) {
            if ($candidate->slugId === $messageSlugId) {
                $message = $candidate;
                break;
            }
        }

        if (!$message instanceof SupportMessage || !$message->hasAttachment()) {
            throw new NotFoundHttpException('Pièce jointe introuvable.');
        }

        Assert::notNull($message->attachmentStoragePath);

        return new RedirectResponse($this->documentStorage->getTemporaryUrl($message->attachmentStoragePath));
    }
}
