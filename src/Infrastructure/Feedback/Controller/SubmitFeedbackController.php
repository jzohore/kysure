<?php

declare(strict_types=1);

namespace App\Infrastructure\Feedback\Controller;

use App\Application\Feedback\UseCase\SubmitFeedbackUseCase;
use App\Domain\Workspace\Service\CurrentUserProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Soumission du widget de feedback flottant (visible uniquement en dev/staging, cf.
 * `base_app.html.twig`). Endpoint JSON appelé en fetch() par `feedback_widget_controller.js` —
 * protégé par la vérification same-origin par défaut de Symfony sur les requêtes POST, aucun
 * jeton CSRF dédié à gérer côté JS.
 */
#[AsController]
#[Route(path: '/app/feedback', name: 'app_feedback_submit', methods: ['POST'])]
final class SubmitFeedbackController extends AbstractController
{
    private const int MAX_MESSAGE_LENGTH = 4000;

    public function __construct(
        private readonly SubmitFeedbackUseCase $submitFeedback,
        private readonly CurrentUserProvider $currentUserProvider,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        $message = is_string($payload['message'] ?? null) ? trim($payload['message']) : '';
        $pageUrl = is_string($payload['pageUrl'] ?? null) ? $payload['pageUrl'] : ($request->headers->get('referer') ?? '');
        $pageTitle = is_string($payload['pageTitle'] ?? null) ? $payload['pageTitle'] : null;

        if ('' === $message || mb_strlen($message) > self::MAX_MESSAGE_LENGTH) {
            return new JsonResponse(['error' => 'Message invalide.'], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        ($this->submitFeedback)($this->currentUserProvider->getUser(), $message, $pageUrl, $pageTitle);

        return new JsonResponse(['success' => true], JsonResponse::HTTP_CREATED);
    }
}
