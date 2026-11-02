<?php

declare(strict_types=1);

namespace App\Infrastructure\Compliance\Controller;

use App\Application\Compliance\UseCase\ComplianceFolder\DeleteComplianceFolderUseCase;
use App\Domain\Compliance\Entity\ComplianceFolder;
use App\Domain\Compliance\Exception\CannotDeleteActiveFolderException;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
#[Route(path: '/app/compliance/{slugId}/{type}/{method}/delete', name: 'app_compliance_delete_folder', methods: ['POST'])]
class DeleteComplianceController extends AbstractController
{
    public function __construct(
        private readonly DeleteComplianceFolderUseCase $deleteComplianceFolderUseCase,
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
        $token = (string) $request->request->get('_token', '');
        $slug = $complianceFolder->slugId;

        if (!$this->isCsrfTokenValid('delete_folder_' . $slug, $token)) {
            $this->addFlash('error', 'Jeton de sécurité invalide ou expiré.');

            return $this->redirectToRoute('app_compliance_method_new', [
                'type' => $type,
                'method' => $method,
                'slugId' => $complianceFolder->slugId,
            ]);
        }

        try {
            $reference = $complianceFolder->reference;
            ($this->deleteComplianceFolderUseCase)($complianceFolder);

            $this->addFlash('success', sprintf('Le dossier %s a été supprimé avec succès.', $reference));
        } catch (CannotDeleteActiveFolderException $exception) {
            $this->addFlash('error', $exception->getMessage());
            $this->logger->warning('Tentative de suppression d\'un dossier actif', ['id' => $complianceFolder->id]);
        } catch (\Exception $exception) {
            $this->addFlash('error', 'Une erreur technique est survenue lors de la suppression.');
            $this->logger->error('Erreur lors de la suppression du dossier KYC', [
                'exception' => $exception->getMessage(),
            ]);
        }

        return $this->redirectToRoute('app_compliance_list');
    }
}
