<?php

declare(strict_types=1);

namespace App\Infrastructure\Compliance\Controller;

use App\Application\Compliance\UseCase\ComplianceFolder\ComplianceFolderShowAssembler;
use App\Domain\Compliance\Entity\ComplianceFolder;
use App\Infrastructure\Compliance\Voter\ComplianceFolderVoter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[AsController]
#[Route(path: '/app/compliance/show/{slugId}', name: 'app_compliance_show', methods: ['GET'])]
#[IsGranted(ComplianceFolderVoter::VIEW, subject: 'complianceFolder', message: 'Ce dossier est strictement confidentiel.')]
class ComplianceShowController extends AbstractController
{
    public function __construct(private readonly ComplianceFolderShowAssembler $assembler)
    {
    }

    public function __invoke(
        #[MapEntity(mapping: ['slugId' => 'slugId'])]
        ComplianceFolder $complianceFolder,
    ): Response {
        $viewDto = $this->assembler->assemble($complianceFolder);

        if ($viewDto->isDraft) {
            return $this->redirectToRoute('app_compliance_method_new', [
                'slugId' => $viewDto->slugId,
                'type' => $viewDto->isKyb ? 'business' : 'individual',
                'method' => $viewDto->method,
            ]);
        }

        return $this->render('@app/compliance/compliance_show.html.twig', [
            'dto' => $viewDto,
            'page_title' => 'Dossier KYC / KYB',
            // Valider / refuser les pièces : même habilitation qu'approuver le dossier (« valider des actes »).
            'can_review_documents' => $this->isGranted(ComplianceFolderVoter::APPROVE, $complianceFolder),
        ]);
    }
}
