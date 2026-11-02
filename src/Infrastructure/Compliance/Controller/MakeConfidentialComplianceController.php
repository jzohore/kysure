<?php

declare(strict_types=1);

namespace App\Infrastructure\Compliance\Controller;

use App\Application\Compliance\UseCase\ComplianceFolder\MakeConfidentialUseCase;
use App\Domain\Compliance\Entity\ComplianceFolder;
use App\Domain\User\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;

#[AsController]
#[Route(path: '/app/compliance/{slugId}/{type}/{method}/confidential', name: 'app_compliance_make_confidential_folder', methods: ['POST'])]
#[IsCsrfTokenValid('make-confidential-compliance')]
class MakeConfidentialComplianceController extends AbstractController
{
    public function __construct(
        private readonly MakeConfidentialUseCase $makeConfidentialUseCase,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(
        string $type,
        string $method,
        #[MapEntity(mapping: ['slugId' => 'slugId'])] ComplianceFolder $complianceFolder,
        #[CurrentUser] User $user,
        Request $request,
    ): RedirectResponse {
        $isConfidential = 'false' != $request->request->get('folder_confidential_value');

        try {
            $allowedUsers = [$user];

            ($this->makeConfidentialUseCase)($complianceFolder, $allowedUsers, $isConfidential);

            $reference = $complianceFolder->reference;

            $this->addFlash('success', sprintf('Le dossier "%s" est désormais %s.', $reference, $isConfidential ? 'confidentiel' : 'accessible'));

            $this->logger->info(sprintf('Dossier rendu %s avec succès', $isConfidential ? 'confidentiel' : 'accessible'), [
                'folder_id' => $complianceFolder->id,
                'locked_by_user' => $user->getFullName(),
            ]);
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('warning', $e->getMessage());
        } catch (\Exception $exception) {
            // 🪄 5. On gère les vraies erreurs serveur (ex: BDD down)
            $this->addFlash('error', 'Une erreur technique est survenue lors de la sécurisation du dossier.');
            $this->logger->error('Erreur critique lors de la sécurisation du dossier KYC', [
                'folder_id' => $complianceFolder->id ?? 'inconnu',
                'exception' => $exception->getMessage(),
            ]);
        }

        return new RedirectResponse($this->urlGenerator->generate('app_compliance_method_new', [
            'type' => $type,
            'method' => $method,
            'slugId' => $complianceFolder->slugId,
        ]));
    }
}
