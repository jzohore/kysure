<?php

declare(strict_types=1);

namespace App\Infrastructure\Support\Controller;

use App\Domain\Support\Entity\SupportThread;
use App\Infrastructure\Support\Voter\SupportThreadVoter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;

#[AsController]
#[Route(path: '/app/billing/support/{slugId}', name: 'app_support_show', methods: ['GET', 'POST'])]
#[Route(path: '/admin/support/{slugId}', name: 'admin_support_show', methods: ['GET', 'POST'])]
readonly class SupportShowController
{
    public function __construct(
        private Environment $twig,
    ) {
    }

    /**
     * @throws RuntimeError
     * @throws SyntaxError
     * @throws LoaderError
     */
    #[IsGranted(SupportThreadVoter::VIEW, subject: 'thread')]
    public function __invoke(
        #[MapEntity(mapping: ['slugId' => 'slugId'])]
        SupportThread $thread,
        Request $request,
    ): Response {
        return new Response(
            $this->twig->render('@admin/support/show.html.twig', [
                'page_title' => 'Support & Tickets',
                'thread' => $thread,
            ])
        );
    }
}
