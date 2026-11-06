<?php

declare(strict_types=1);

namespace App\Infrastructure\Compliance\Twig\Components\Onboarding;

use App\Application\Compliance\UseCase\ComplianceFolder\DiscardFlashDraftUseCase;
use App\Domain\Compliance\Enum\FolderType;
use App\Domain\Compliance\Factory\ComplianceFolderFactory;
use App\Domain\Compliance\Repository\ComplianceFolderRepositoryInterface;
use App\Domain\Compliance\Service\DocumentRequirementEngine;
use App\Domain\Workspace\Entity\Workspace;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveListener;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\LiveComponent\LiveResponder;
use Webmozart\Assert\Assert;

#[AsLiveComponent(
    name: 'FlashStep1TypeComponent',
    template: 'components/Compliance/Onboarding/FlashStep1TypeComponent.html.twig',
)]
final class FlashStep1TypeComponent extends AbstractController
{
    use DefaultActionTrait;

    #[LiveProp(writable: false)]
    public Workspace $workspace;

    #[LiveProp]
    public bool $isVisible = true; // S'affiche par défaut

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly ComplianceFolderRepositoryInterface $repository,
        private readonly LoggerInterface $logger,
        private readonly DiscardFlashDraftUseCase $discardFlashDraftUseCase,
        private readonly ComplianceFolderFactory $folderFactory,
        private readonly DocumentRequirementEngine $requirementEngine,
        private readonly LiveResponder $responder,
    ) {
    }

    public function mount(Workspace $workspace): void
    {
        $this->workspace = $workspace;

        $draftId = $this->requestStack->getSession()->get('flash_draft_folder_id');

        // Si un dossier existe déjà en session, on masque cette étape !
        if (is_string($draftId) || is_int($draftId)) {
            $this->isVisible = false;
        }
    }

    #[LiveAction]
    public function selectType(
        #[LiveArg] string $type,
    ): void {
        try {
            Assert::inArray($type, ['individual', 'company'], 'Type d\'entité invalide.');

            $enumType = 'individual' === $type ? FolderType::INDIVIDUAL : FolderType::BUSINESS;

            $folder = $this->folderFactory->createDraft(
                type: $enumType,
                workspace: $this->workspace,
                email: 'anonymous@flash.kysure.local',
                method: 'flash_qr_code'
            );

            $this->requirementEngine->generateBaseRequirements($folder);
            $this->repository->save($folder);

            // On stocke en session
            $this->requestStack->getSession()->set('flash_draft_folder_id', (string) $folder->id);

            // On se masque et on prévient l'Étape 2 de s'afficher !
            $this->isVisible = false;
            $this->responder->emit('draftCreated');
        } catch (\Exception $e) {
            $this->logger->critical('Crash Flash Step 1', ['error' => $e->getMessage()]);
            $this->addFlash('error', 'Une erreur technique est survenue.');
        }
    }

    /**
     * 🪄 Écoute le bouton "Retour au choix" de l'Étape 2.
     *
     * Page publique : aucun utilisateur connecté. L'id lu en session peut être
     * absent, périmé (dossier purgé) ou ne plus être un brouillon vierge — dans
     * tous les cas on libère la session et on réaffiche le choix : le pire est
     * de laisser intact un dossier qu'on n'avait pas le droit de supprimer.
     */
    #[LiveListener('resetToStep1')]
    public function onReset(): void
    {
        $session = $this->requestStack->getSession();
        $draftId = $session->get('flash_draft_folder_id');

        if (is_string($draftId) || is_int($draftId)) {
            try {
                ($this->discardFlashDraftUseCase)((string) $draftId, $this->workspace);
            } catch (\DomainException $e) {
                // Garde métier (autre cabinet, autre canal, dossier engagé) : on conserve le dossier.
                $this->logger->warning('Flash : brouillon non abandonné', [
                    'folder_id' => (string) $draftId,
                    'workspace' => $this->workspace->slugId,
                    'reason' => $e->getMessage(),
                ]);
            } catch (\Throwable $e) {
                $this->logger->error('Flash : échec de l\'abandon du brouillon', [
                    'folder_id' => (string) $draftId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $session->remove('flash_draft_folder_id');
        $this->isVisible = true;
    }
}
