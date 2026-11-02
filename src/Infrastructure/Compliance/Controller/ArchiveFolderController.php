<?php

declare(strict_types=1);

namespace App\Infrastructure\Compliance\Controller;

use App\Application\Compliance\UseCase\ComplianceFolder\ArchiveFolderUseCase;
use App\Domain\Compliance\Entity\ComplianceFolder;
use App\Domain\Shared\Exception\AbstractDomainException;
use App\Infrastructure\Workspace\Voter\WorkspaceInvitationVoter;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
#[Route(path: '/app/compliance/{slugId}/{type}/{method}/archive', name: 'app_compliance_archive_folder', methods: ['POST'])]
final class ArchiveFolderController extends AbstractController
{
    public function __construct(
        private readonly ArchiveFolderUseCase $useCase,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(
        #[MapEntity(mapping: ['slugId' => 'slugId'])]
        ComplianceFolder $complianceFolder,
        Request $request,
        string $type,
        string $method,
    ): RedirectResponse {
        // Archiver suit la posture de délégation du cabinet (admin, ou collaborateur si délégué).
        $this->denyAccessUnlessGranted(WorkspaceInvitationVoter::FOLDER_ARCHIVE, $complianceFolder->workspace);

        $token = (string) $request->request->get('_token', '');
        $slug = $complianceFolder->slugId;

        if (!$this->isCsrfTokenValid('archive_folder_' . $slug, $token)) {
            $this->addFlash('error', 'Jeton de sécurité invalide ou expiré.');

            return $this->redirectToRoute('app_compliance_method_new', [
                'type' => $type,
                'method' => $method,
                'slugId' => $complianceFolder->slugId,
            ]);
        }
        try {
            ($this->useCase)($complianceFolder);

            $this->addFlash('success', sprintf('Le dossier %s a été archivé avec succès.', $complianceFolder->reference));
        } catch (AbstractDomainException $e) {
            $this->addFlash('error', $e->getMessage());
            $this->logger->warning('Tentative de archivage d\'un dossier', ['id' => $complianceFolder->id]);
        } catch (\Exception $exception) {
            // 5. On gère les vraies erreurs serveur (ex: BDD down)
            $this->addFlash('error', 'Une erreur technique est survenue lors de la suppression.');
            $this->logger->error('Erreur lors de l\'archivage du dossier KYC', [
                'exception' => $exception->getMessage(),
            ]);
        }

        return $this->redirectToRoute('app_compliance_list');
    }
}
