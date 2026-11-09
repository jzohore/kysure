<?php

declare(strict_types=1);

namespace App\Infrastructure\AdequacyReport\Controller;

use App\Application\AdequacyReport\UseCase\AdequacyReportDocumentAssembler;
use App\Application\AdequacyReport\UseCase\AdequacyReportDraftComposer;
use App\Domain\Compliance\Entity\ComplianceFolder;
use App\Infrastructure\Compliance\Voter\ComplianceFolderVoter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Aperçu du brouillon de rapport d'adéquation, rendu par le même gabarit que le PDF final :
 * le CGP relit exactement ce qui sera figé. Rien n'est persisté.
 *
 * En POST (formulaire du composant, nouvel onglet) : la justification du conseil peut contenir
 * des données personnelles, elle ne doit jamais transiter dans une URL.
 */
#[AsController]
#[IsGranted('ROLE_USER')]
#[Route(path: '/app/compliance/show/{slugId}/rapport-adequation/apercu', name: 'app_adequacy_report_draft_preview', methods: ['POST'])]
final class AdequacyReportDraftPreviewController extends AbstractController
{
    public const string CSRF_TOKEN_ID = 'adequacy_report_draft_preview';

    public function __construct(
        private readonly AdequacyReportDraftComposer $composer,
        private readonly AdequacyReportDocumentAssembler $documentAssembler,
    ) {
    }

    public function __invoke(
        Request $request,
        #[MapEntity(mapping: ['slugId' => 'slugId'])]
        ComplianceFolder $folder,
    ): Response {
        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Jeton de sécurité invalide.');
        }

        $this->denyAccessUnlessGranted(ComplianceFolderVoter::VIEW, $folder);

        /** @var list<string> $checkSlugIds */
        $checkSlugIds = array_values(array_filter($request->request->all('checks'), \is_string(...)));

        try {
            $draft = $this->composer->compose($folder, $checkSlugIds, $request->request->getString('rationale'));
        } catch (\DomainException $e) {
            return $this->render('@app/pdf/adequacy_report_template.html.twig', [
                'report' => null,
                'draft_error' => $e->getMessage(),
                'is_pdf' => false,
            ], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        $response = $this->render('@app/pdf/adequacy_report_template.html.twig', [
            'report' => $this->documentAssembler->fromDraft($draft->content),
            'draft_error' => null,
            'is_pdf' => false,
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
