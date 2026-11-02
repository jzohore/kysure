<?php

declare(strict_types=1);

namespace App\Infrastructure\User\Twig\Components\Client;

use App\Application\Compliance\UseCase\ComplianceFolder\SubmitForReviewUseCase;
use App\Domain\Compliance\Entity\ComplianceFolder;
use App\Domain\Compliance\Exception\ComplianceFolderNotFoundException;
use App\Domain\Compliance\Repository\ComplianceFolderRepositoryInterface;
use App\Domain\Kyc\Enum\DocumentStatus;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent(
    name: 'ClientPortalDocumentListComponent',
    template: 'components/User/Client/ClientPortalDocumentListComponent.html.twig',
    // Servi sous /portal pour rester dans le firewall `portal` (cf. config/routes/ux_live_component.yaml) :
    // les LiveActions de ce composant étaient jusqu'ici jouables anonymement via /_components.
    route: 'portal_ux_live_component',
)]
final class ClientPortalDocumentListComponent extends AbstractController
{
    use DefaultActionTrait;

    // 🚨 writable: false -> Le client ne peut JAMAIS altérer l'entité via le DOM
    #[LiveProp(writable: false)]
    public string $folderId;

    private ?ComplianceFolder $folderCached = null;

    public function __construct(
        private readonly ComplianceFolderRepositoryInterface $folderRepository, private readonly SubmitForReviewUseCase $useCase,
    ) {
    }

    public function getFolder(): ComplianceFolder
    {
        if (!$this->folderCached instanceof ComplianceFolder) {
            $this->folderCached = $this->folderRepository->findOneBySlugId($this->folderId);

            if (!$this->folderCached instanceof ComplianceFolder) {
                throw ComplianceFolderNotFoundException::withId($this->folderId);
            }
        }

        return $this->folderCached;
    }

    public function hasProcessingDocuments(): bool
    {
        // On utilise la méthode de récupération interne
        foreach ($this->getFolder()->documents as $document) {
            if (DocumentStatus::PROCESSING === $document->status) {
                return true;
            }
        }

        return false;
    }

    #[LiveAction]
    public function submitForReview(): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        // Sécurité : On revérifie la condition métier côté serveur
        if (!$this->getFolder()->canBeSubmitted()) {
            $this->addFlash('error', 'Le dossier ne peut pas être soumis dans son état actuel.');

            return $this->redirectToRoute('portal_folders_show', ['id' => $this->getFolder()->slugId]);
        }

        try {
            ($this->useCase)($this->getFolder());
            $this->addFlash('success', 'Votre dossier a été soumis pour analyse.');

            // Optionnel : On peut forcer un re-render complet pour masquer le bloc bouton
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('portal_folders_show', ['id' => $this->getFolder()->slugId]);
    }
}
