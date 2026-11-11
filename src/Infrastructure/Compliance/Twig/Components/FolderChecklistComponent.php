<?php

declare(strict_types=1);

namespace App\Infrastructure\Compliance\Twig\Components;

use App\Application\Compliance\DTO\Response\FolderChecklistResponse;
use App\Application\Compliance\UseCase\ComplianceFolder\GetFolderChecklistUseCase;
use App\Domain\Compliance\Entity\ComplianceFolder;
use App\Domain\Compliance\Repository\ComplianceFolderRepositoryInterface;
use App\Infrastructure\Compliance\Voter\ComplianceFolderVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
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

    #[LiveProp]
    public string $folderSlugId;

    public function __construct(
        private readonly ComplianceFolderRepositoryInterface $folderRepository,
        private readonly GetFolderChecklistUseCase $getChecklist,
    ) {
    }

    public function getChecklist(): FolderChecklistResponse
    {
        $folder = $this->folderRepository->findOneBySlugId($this->folderSlugId);
        Assert::isInstanceOf($folder, ComplianceFolder::class, 'Dossier introuvable.');
        // Chaque re-rendu Live est une requête à part entière : on revérifie l'accès.
        $this->denyAccessUnlessGranted(ComplianceFolderVoter::VIEW, $folder);

        return ($this->getChecklist)($folder);
    }

    #[LiveListener('adequacyCheck:recorded')]
    #[LiveListener('adequacyReport:changed')]
    public function refresh(): void
    {
        // Rien à faire : le re-rendu recalcule la checklist.
    }
}
