<?php

declare(strict_types=1);

namespace App\Infrastructure\Controller\Admin\Feedback;

use App\Application\Feedback\UseCase\ListFeedbackUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[AsController]
#[IsGranted('ROLE_ADMIN')]
#[Route(path: '/admin/feedback', name: 'admin_feedback_list', methods: ['GET'])]
final class FeedbackListController extends AbstractController
{
    public function __construct(
        private readonly ListFeedbackUseCase $listFeedback,
    ) {
    }

    public function __invoke(): Response
    {
        return $this->render('@admin/feedback/list.html.twig', [
            'page_title' => 'Retours des testeurs',
            'feedback_entries' => ($this->listFeedback)(),
        ]);
    }
}
