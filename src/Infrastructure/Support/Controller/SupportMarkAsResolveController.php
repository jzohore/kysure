<?php

declare(strict_types=1);

namespace App\Infrastructure\Support\Controller;

use App\Application\Support\UseCase\MarkAResolveUseCase;
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
#[Route(path: '/admin/support/mark-as-resolve/{slugId}', name: 'admin_support_mark_as_resolve', methods: ['POST'])]
#[IsGranted('ROLE_SUPER_ADMIN')]
#[IsCsrfTokenValid('support-resolve')]
readonly class SupportMarkAsResolveController
{
    public function __construct(
        private MarkAResolveUseCase $markAResolveUseCase,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function __invoke(
        #[MapEntity(mapping: ['slugId' => 'slugId'])]
        SupportThread $thread,
    ): Response {
        $this->markAResolveUseCase->execute($thread);

        return new RedirectResponse($this->urlGenerator->generate('admin_support_show', [
            'slugId' => $thread->slugId,
        ]));
    }
}
