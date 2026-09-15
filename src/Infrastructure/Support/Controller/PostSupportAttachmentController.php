<?php

declare(strict_types=1);

namespace App\Infrastructure\Support\Controller;

use App\Application\Support\UseCase\PostSupportMessageUseCase;
use App\Domain\Support\Enum\SupportCategory;
use App\Domain\Support\Enum\SupportTopic;
use App\Domain\User\Entity\User;
use App\Domain\Workspace\Service\CurrentWorkspaceProvider;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Upload d'une pièce jointe côté client, hors cycle LiveComponent : un UploadedFile
 * ne survit pas entre deux requêtes LiveComponent séparées (il n'est pas sérialisable
 * dans l'état du composant), donc l'envoi doit passer par un POST HTTP classique.
 * Le widget déclenche ensuite un `live:render` pour rafraîchir la conversation.
 */
#[AsController]
#[Route(path: '/app/billing/support/attachment', name: 'app_support_attachment_upload', methods: ['POST'])]
final readonly class PostSupportAttachmentController
{
    public function __construct(
        private PostSupportMessageUseCase $postMessageUseCase,
        private CurrentWorkspaceProvider $workspaceProvider,
    ) {
    }

    public function __invoke(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $file = $request->files->get('attachment');
        if (!$file instanceof UploadedFile) {
            return new JsonResponse(['ok' => false, 'message' => 'Aucun fichier reçu.'], Response::HTTP_BAD_REQUEST);
        }

        $category = SupportCategory::tryFrom((string) $request->request->get('category')) ?? SupportCategory::OTHER;
        $topic = SupportTopic::tryFrom((string) $request->request->get('topic')) ?? SupportTopic::OTHER;
        $content = (string) $request->request->get('content', '');

        try {
            $this->postMessageUseCase->execute(
                $this->workspaceProvider->getWorkspace(),
                $user,
                $content,
                $category,
                $topic,
                null,
                $file,
            );
        } catch (\DomainException $e) {
            // 🛡️ Safe to expose : erreur métier destinée à l'utilisateur (fichier trop lourd/type non autorisé)
            return new JsonResponse(['ok' => false, 'message' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(['ok' => true]);
    }
}
