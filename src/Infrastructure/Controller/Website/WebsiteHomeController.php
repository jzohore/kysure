<?php

declare(strict_types=1);

namespace App\Infrastructure\Controller\Website;

use App\Application\Billing\UseCase\Pricing\GetPublicPricingUseCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;

#[AsController]
#[Route(path: '/home', name: 'website_home')]
final readonly class WebsiteHomeController
{
    public function __construct(
        private GetPublicPricingUseCase $getPublicPricing,
    ) {
    }

    /**
     * @throws RuntimeError
     * @throws SyntaxError
     * @throws LoaderError
     */
    public function __invoke(
        Environment $twig,
    ): Response {
        return new Response(
            $twig->render('@website/home.html.twig', [
                'pricing' => ($this->getPublicPricing)(),
            ])
        );
    }
}
