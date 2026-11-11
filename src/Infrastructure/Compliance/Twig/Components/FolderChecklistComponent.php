<?php

declare(strict_types=1);

namespace App\Infrastructure\Compliance\Twig\Components;

use App\Application\Compliance\DTO\Response\FolderChecklistResponse;
use App\Application\Compliance\UseCase\ComplianceFolder\GetFolderChecklistUseCase;
use App\Application\Compliance\UseCase\ElectronicDelivery\RequestElectronicDeliveryConsentUseCase;
use App\Domain\Compliance\Entity\ComplianceFolder;
use App\Domain\Compliance\Repository\ComplianceFolderRepositoryInterface;
use App\Infrastructure\Compliance\Voter\ComplianceFolderVoter;
use App\Infrastructure\Shared\Component\LiveFlashTrait;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveListener;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Webmozart\Assert\Assert;

/**
 * Checklist de pilotage du dossier (lot 8), en lecture seule : « où j'en suis ». Se recalcule
 * quand une vérification ou un rapport d'adéquation change sur la même page.
 */
#[AsLiveComponent(
    name: 'FolderChecklistComponent',
    template: 'components/Compliance/FolderChecklistComponent.html.twig',
)]
class FolderChecklistComponent extends AbstractController
{
    use DefaultActionTrait;
    use LiveFlashTrait;

    #[LiveProp]
    public string $folderSlugId;

    public function __construct(
        private readonly ComplianceFolderRepositoryInterface $folderRepository,
        private readonly GetFolderChecklistUseCase $getChecklist,
        private readonly RequestElectronicDeliveryConsentUseCase $requestConsent,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getChecklist(): FolderChecklistResponse
    {
        return ($this->getChecklist)($this->folder());
    }

    public function canAct(): bool
    {
        $folder = $this->folder();

        return !$folder->isArchived() && $this->isGranted(ComplianceFolderVoter::EDIT, $folder);
    }

    /** Avenant au DER : demande au client son accord pour la remise électronique. */
    #[LiveAction]
    public function requestElectronicDeliveryConsent(): void
    {
        $this->clearLiveFlash();
        $folder = $this->folder();
        $this->denyAccessUnlessGranted(ComplianceFolderVoter::EDIT, $folder);

        try {
            ($this->requestConsent)($folder);
            $this->addLiveFlash('success', 'Demande envoyée : le client reçoit un e-mail pour donner son accord depuis son espace.');
        } catch (\DomainException $e) {
            $this->addLiveFlash('error', $e->getMessage());
        } catch (\Throwable $e) {
            $this->logger->error('Crash lors de la demande d\'accord de remise électronique.', ['folder_slug_id' => $this->folderSlugId, 'exception' => $e]);
            $this->addLiveFlash('error', 'Erreur système lors de l\'envoi de la demande.');
        }
    }

    private function folder(): ComplianceFolder
    {
        $folder = $this->folderRepository->findOneBySlugId($this->folderSlugId);
        Assert::isInstanceOf($folder, ComplianceFolder::class, 'Dossier introuvable.');
        // Chaque re-rendu Live est une requête à part entière : on revérifie l'accès.
        $this->denyAccessUnlessGranted(ComplianceFolderVoter::VIEW, $folder);

        return $folder;
    }

    #[LiveListener('adequacyCheck:recorded')]
    #[LiveListener('adequacyReport:changed')]
    public function refresh(): void
    {
        // Rien à faire : le re-rendu recalcule la checklist.
    }
}
