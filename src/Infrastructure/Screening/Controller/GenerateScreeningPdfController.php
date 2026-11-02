<?php

declare(strict_types=1);

namespace App\Infrastructure\Screening\Controller;

use App\Application\Screening\UseCase\GenerateScreeningPdfUseCase;
use App\Application\Screening\UseCase\GetScreeningInfo;
use App\Domain\Screening\Entity\ScreeningAudit;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[AsController]
#[Route(path: '/app/screening/{slugId}/pdf', name: 'app_screening_pdf', methods: ['POST'])]
readonly class GenerateScreeningPdfController
{
    public function __construct(
        private GenerateScreeningPdfUseCase $generateScreeningPdfUseCase,
        private GetScreeningInfo $getScreeningInfo,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * @throws ExceptionInterface
     */
    public function __invoke(
        #[MapEntity(mapping: ['slugId' => 'slugId'])]
        ScreeningAudit $audit,
    ): RedirectResponse {
        ($this->generateScreeningPdfUseCase)($audit);
        $screening = ($this->getScreeningInfo)($audit);

        return new RedirectResponse(url: $this->urlGenerator->generate('app_screening_show', ['slugId' => $screening->slugId]));
    }
}
