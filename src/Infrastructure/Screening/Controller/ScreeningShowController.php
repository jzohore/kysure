<?php

declare(strict_types=1);

namespace App\Infrastructure\Screening\Controller;

use App\Application\Screening\UseCase\GetScreeningInfo;
use App\Domain\Screening\Entity\ScreeningAudit;
use App\Domain\User\Entity\User;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;

#[AsController]
#[Route(path: '/app/screening/{slugId}', name: 'app_screening_show', methods: ['GET', 'POST'])]
readonly class ScreeningShowController
{
    public function __construct(
        private GetScreeningInfo $getScreeningInfo,
    ) {
    }

    /**
     * @throws RuntimeError
     * @throws SyntaxError
     * @throws LoaderError
     */
    public function __invoke(
        #[MapEntity(mapping: ['slugId' => 'slugId'])]
        ScreeningAudit $audit,
        Environment $twig,
        #[CurrentUser]
        User $user,
    ): Response {
        $screening = ($this->getScreeningInfo)($audit);

        return new Response(
            $twig->render('@app/screening/screening_show.html.twig', [
                'page_title' => 'Rapport de Criblage -',
                'audit' => $screening,
                'raw_audit' => $audit,
            ])
        );
    }
}
