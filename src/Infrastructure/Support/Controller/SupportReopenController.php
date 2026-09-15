<?php

declare(strict_types=1);

namespace App\Infrastructure\Support\Controller;

use App\Application\Support\UseCase\ReopenSupportThreadUseCase;
use App\Domain\Support\Entity\SupportThread;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[AsController]
#[Route(path: '/admin/support/reopen/{slugId}', name: 'admin_support_reopen', methods: ['POST'])]
#[IsGranted('ROLE_SUPER_ADMIN')]
#[IsCsrfTokenValid('support-reopen')]
readonly class SupportReopenController
{
    public function __construct(
        private ReopenSupportThreadUseCase $reopenSupportThreadUseCase,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function __invoke(
        #[MapEntity(mapping: ['slugId' => 'slugId'])]
        SupportThread $thread,
    ): Response {
        $this->reopenSupportThreadUseCase->execute($thread);

        return new RedirectResponse($this->urlGenerator->generate('admin_support_show', [
            'slugId' => $thread->slugId,
        ]));
    }
}
