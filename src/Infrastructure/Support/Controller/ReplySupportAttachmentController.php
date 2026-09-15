<?php

declare(strict_types=1);

namespace App\Infrastructure\Support\Controller;

use App\Application\Support\UseCase\ReplyToSupportThreadUseCase;
use App\Domain\Support\Entity\SupportThread;
use App\Domain\Support\Enum\SupportSenderType;
use App\Infrastructure\Support\Voter\SupportThreadVoter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Upload d'une pièce jointe côté admin, hors cycle LiveComponent (voir
 * PostSupportAttachmentController pour le détail de la contrainte technique).
 */
#[AsController]
#[Route(path: '/admin/support/{slugId}/attachment', name: 'admin_support_attachment_upload', methods: ['POST'])]
final readonly class ReplySupportAttachmentController
{
    public function __construct(
        private ReplyToSupportThreadUseCase $replyUseCase,
    ) {
    }

    #[IsGranted(SupportThreadVoter::VIEW, subject: 'thread')]
    public function __invoke(
        #[MapEntity(mapping: ['slugId' => 'slugId'])]
        SupportThread $thread,
        Request $request,
    ): JsonResponse {
        $file = $request->files->get('attachment');
        if (!$file instanceof UploadedFile) {
            return new JsonResponse(['ok' => false, 'message' => 'Aucun fichier reçu.'], Response::HTTP_BAD_REQUEST);
        }

        $content = (string) $request->request->get('content', '');

        try {
            $this->replyUseCase->execute($thread, $content, SupportSenderType::ADMIN, $file);
        } catch (\DomainException $e) {
            return new JsonResponse(['ok' => false, 'message' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(['ok' => true]);
    }
}
