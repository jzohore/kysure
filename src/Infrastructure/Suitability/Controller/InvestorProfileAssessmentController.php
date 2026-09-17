<?php

declare(strict_types=1);

namespace App\Infrastructure\Suitability\Controller;

use App\Application\Suitability\UseCase\FindInForceValidatedProfileUseCase;
use App\Application\Suitability\UseCase\GetOrCreateDraftAssessmentUseCase;
use App\Domain\Suitability\Entity\ValidatedInvestorProfile;
use App\Domain\User\Entity\Client;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[AsController]
#[IsGranted('ROLE_CLIENT', message: 'Espace strictement réservé aux clients.')]
final class InvestorProfileAssessmentController extends AbstractController
{
    public function __construct(
        private readonly GetOrCreateDraftAssessmentUseCase $getOrCreateDraftAssessmentUseCase,
        private readonly FindInForceValidatedProfileUseCase $findInForceValidatedProfileUseCase,
    ) {
    }

    #[Route(path: '/portal/profil-investisseur', name: 'app_portal_investor_profile', methods: ['GET'])]
    public function __invoke(): Response
    {
        /** @var Client $client */
        $client = $this->getUser();

        if (($this->findInForceValidatedProfileUseCase)($client) instanceof ValidatedInvestorProfile) {
            // Profil déjà validé par le CGP : pas de nouveau questionnaire tant qu'il n'a pas
            // été révoqué, sinon le client pourrait écraser lui-même la version qui fait foi.
            return $this->redirectToRoute('app_portal_investor_profile_done');
        }

        $assessment = ($this->getOrCreateDraftAssessmentUseCase)($client);

        if ($assessment->isSubmitted()) {
            return $this->redirectToRoute('app_portal_investor_profile_done');
        }

        $workspace = $client->workspaces->first();

        return $this->render('@app/client/investor_profile_questionnaire.html.twig', [
            'assessment_slug_id' => $assessment->slugId,
            'company_name' => false !== $workspace ? $workspace->name : 'KYSURE',
        ]);
    }
}
