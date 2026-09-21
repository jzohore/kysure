<?php

declare(strict_types=1);

namespace App\Infrastructure\Compliance\Twig\Components;

use App\Application\Compliance\UseCase\ComplianceFolder\ApproveComplianceFolderUseCase;
use App\Application\Compliance\UseCase\ComplianceFolder\RejectComplianceFolderUseCase;
use App\Domain\Compliance\Entity\ComplianceFolder;
use App\Domain\Compliance\Enum\ComplianceFolderStatus;
use App\Domain\Compliance\Enum\RiskLevel;
use App\Domain\Compliance\Repository\ComplianceFolderRepositoryInterface;
use App\Domain\Suitability\Entity\ValidatedInvestorProfile;
use App\Domain\Suitability\Repository\ValidatedInvestorProfileRepositoryInterface;
use App\Domain\User\Entity\Client;
use App\Infrastructure\Compliance\Voter\ComplianceFolderVoter;
use App\Infrastructure\Shared\Component\LiveFlashTrait;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Webmozart\Assert\Assert;

/**
 * Décision finale du dossier LCB-FT (conforme / non conforme). N'apparaît que quand le dossier
 * est en cours d'analyse ({@see ComplianceFolderStatus::IN_REVIEW}) — avant ou après, il n'y a
 * rien à décider ici. Décision lot 5 : l'approbation est bloquée tant qu'aucun profil
 * investisseur validé n'est en vigueur pour ce client auprès de CE cabinet.
 */
#[AsLiveComponent(
    name: 'ComplianceFolderApprovalComponent',
    template: 'components/Compliance/ComplianceFolderApprovalComponent.html.twig',
)]
class ComplianceFolderApprovalComponent extends AbstractController
{
    use DefaultActionTrait;
    use LiveFlashTrait;

    #[LiveProp]
    public string $folderSlugId;

    #[LiveProp(writable: true)]
    public bool $isRejecting = false;

    #[LiveProp(writable: true)]
    public string $selectedRiskLevel = '';

    #[LiveProp(writable: true)]
    public string $comments = '';

    #[LiveProp(writable: true)]
    public string $rejectReason = '';

    private ?ComplianceFolder $folderCache = null;

    public function __construct(
        private readonly ComplianceFolderRepositoryInterface $folderRepository,
        private readonly ValidatedInvestorProfileRepositoryInterface $profileRepository,
        private readonly ApproveComplianceFolderUseCase $approveUseCase,
        private readonly RejectComplianceFolderUseCase $rejectUseCase,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getFolder(): ComplianceFolder
    {
        if (!$this->folderCache instanceof ComplianceFolder) {
            $this->folderCache = $this->folderRepository->findOneBySlugId($this->folderSlugId);
            Assert::notNull($this->folderCache, 'Dossier introuvable.');
        }

        return $this->folderCache;
    }

    public function isInReview(): bool
    {
        return ComplianceFolderStatus::IN_REVIEW === $this->getFolder()->status;
    }

    public function hasValidatedInvestorProfileInForce(): bool
    {
        $folder = $this->getFolder();

        return $folder->client instanceof Client
            && $this->profileRepository->findInForceByClient($folder->client, $folder->workspace) instanceof ValidatedInvestorProfile;
    }

    /**
     * @return list<RiskLevel>
     */
    public function getRiskLevels(): array
    {
        return RiskLevel::cases();
    }

    public function canDecide(): bool
    {
        return $this->isGranted(ComplianceFolderVoter::APPROVE, $this->getFolder());
    }

    #[LiveAction]
    public function toggleReject(): void
    {
        $this->clearLiveFlash();
        $this->isRejecting = !$this->isRejecting;
    }

    #[LiveAction]
    public function approve(): void
    {
        $this->clearLiveFlash();
        $this->denyAccessUnlessGranted(ComplianceFolderVoter::APPROVE, $this->getFolder());

        if ('' === $this->selectedRiskLevel) {
            $this->addLiveFlash('error', 'Merci de sélectionner un niveau de risque.');

            return;
        }

        try {
            ($this->approveUseCase)($this->getFolder(), RiskLevel::from($this->selectedRiskLevel), $this->comments);
            $this->folderCache = null;
            $this->addLiveFlash('success', 'Dossier déclaré conforme.');
        } catch (\DomainException $e) {
            $this->addLiveFlash('error', $e->getMessage());
        } catch (\Throwable) {
            $this->logger->error('Crash lors de l\'approbation du dossier.', ['folder_slug_id' => $this->folderSlugId]);
            $this->addLiveFlash('error', 'Erreur système lors de l\'approbation.');
        }
    }

    #[LiveAction]
    public function reject(): void
    {
        $this->clearLiveFlash();
        $this->denyAccessUnlessGranted(ComplianceFolderVoter::REJECT, $this->getFolder());

        if ('' === trim($this->rejectReason)) {
            $this->addLiveFlash('error', 'Un motif est obligatoire pour rejeter un dossier.');

            return;
        }

        try {
            ($this->rejectUseCase)($this->getFolder(), $this->rejectReason);
            $this->folderCache = null;
            $this->isRejecting = false;
            $this->rejectReason = '';
            $this->addLiveFlash('success', 'Dossier déclaré non conforme.');
        } catch (\DomainException $e) {
            $this->addLiveFlash('error', $e->getMessage());
        } catch (\Throwable) {
            $this->logger->error('Crash lors du rejet du dossier.', ['folder_slug_id' => $this->folderSlugId]);
            $this->addLiveFlash('error', 'Erreur système lors du rejet.');
        }
    }
}
