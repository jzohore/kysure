<?php

declare(strict_types=1);

namespace App\Infrastructure\KYC\Controller;

use App\Application\Kyc\UseCase\GetCurrentKycFolderUseCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;

#[AsController]
#[Route(path: '/app/kyc/{slugId}', name: 'app_kyc_show', methods: ['GET', 'POST'])]
final readonly class KycFolderShowController
{
    public function __construct(
        private GetCurrentKycFolderUseCase $getCurrentKycFolderUseCase,
    ) {
    }

    /**
     * @throws RuntimeError
     * @throws SyntaxError
     * @throws LoaderError
     */
    public function __invoke(
        Environment $twig,
        string $slugId,
    ): Response {
        $folder = ($this->getCurrentKycFolderUseCase)($slugId);

        return new Response(
            $twig->render('@app/kyc/kyc_show.html.twig', [
                'page_title' => 'Dossier ' . $folder->reference,
                'folder' => $folder,
            ])
        );
    }
}
