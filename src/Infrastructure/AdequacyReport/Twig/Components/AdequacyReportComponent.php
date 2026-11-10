<?php

declare(strict_types=1);

namespace App\Infrastructure\AdequacyReport\Twig\Components;

use App\Application\AdequacyReport\UseCase\AdequacyReportDraftComposer;
use App\Application\AdequacyReport\UseCase\RevokeAdequacyReportUseCase;
use App\Application\AdequacyReport\UseCase\ValidateAdequacyReportUseCase;
use App\Domain\AdequacyReport\Entity\AdequacyReport;
use App\Domain\AdequacyReport\Entity\ProductAdequacyCheck;
use App\Domain\AdequacyReport\Enum\MatchSeverity;
use App\Domain\AdequacyReport\Repository\AdequacyReportRepositoryInterface;
use App\Domain\Compliance\Entity\ComplianceFolder;
use App\Domain\Compliance\Repository\ComplianceFolderRepositoryInterface;
use App\Infrastructure\AdequacyReport\Controller\AdequacyReportDraftPreviewController;
use App\Infrastructure\AdequacyReport\Voter\AdequacyReportVoter;
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
 * Rapport d'adéquation (lot 5, issue #32), sur la page dossier : le CGP choisit les
 * vérifications à citer, rédige sa justification du conseil, relit l'aperçu (même gabarit que
 * le PDF), puis valide — le rapport est alors figé, son PDF généré et remis au client.
 * Historique des versions, révocation motivée, signalement si le profil a été révoqué depuis.
 */
#[AsLiveComponent(
    name: 'AdequacyReportComponent',
    template: 'components/AdequacyReport/AdequacyReportComponent.html.twig',
)]
class AdequacyReportComponent extends AbstractController
{
    use DefaultActionTrait;
    use LiveFlashTrait;

    #[LiveProp]
    public string $folderSlugId;

    /** @var array<int, string> cases cochées (le navigateur peut renvoyer des clés non contiguës) */
    #[LiveProp(writable: true)]
    public array $selectedCheckSlugIds = [];

    #[LiveProp(writable: true)]
    public string $rationale = '';

    /** Mention obligatoire de réexamen périodique : '' (pas encore tranché), 'yes' ou 'no'. */
    #[LiveProp(writable: true)]
    public string $periodicReview = '';

    #[LiveProp(writable: true)]
    public bool $isRevoking = false;

    #[LiveProp(writable: true)]
    public string $revokeReason = '';

    private ?ComplianceFolder $folderCache = null;

    /** @var list<AdequacyReport>|null */
    private ?array $reportsCache = null;

    public function __construct(
        private readonly ComplianceFolderRepositoryInterface $folderRepository,
        private readonly AdequacyReportRepositoryInterface $reportRepository,
        private readonly AdequacyReportDraftComposer $composer,
        private readonly ValidateAdequacyReportUseCase $validateUseCase,
        private readonly RevokeAdequacyReportUseCase $revokeUseCase,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function mount(string $folderSlugId): void
    {
        $this->folderSlugId = $folderSlugId;
        $this->preselectSingleEligibleCheck();
    }

    public function getFolder(): ComplianceFolder
    {
        if (!$this->folderCache instanceof ComplianceFolder) {
            $folder = $this->folderRepository->findOneBySlugId($this->folderSlugId);
            Assert::notNull($folder, 'Dossier introuvable.');
            // Chaque re-rendu Live est une requête à part entière : on revérifie l'accès.
            $this->denyAccessUnlessGranted(ComplianceFolderVoter::VIEW, $folder);
            $this->folderCache = $folder;
        }

        return $this->folderCache;
    }

    /**
     * @return list<AdequacyReport>
     */
    public function getReports(): array
    {
        return $this->reportsCache ??= $this->reportRepository->findByFolder($this->getFolder());
    }

    public function getInForceReport(): ?AdequacyReport
    {
        foreach ($this->getReports() as $report) {
            if ($report->isInForce()) {
                return $report;
            }
        }

        return null;
    }

    /**
     * @return list<ProductAdequacyCheck>
     */
    public function getEligibleChecks(): array
    {
        return $this->composer->eligibleChecks($this->getFolder());
    }

    /**
     * Vérifications réellement citables : celles qui ont des frais figés (lot 4).
     *
     * @return list<ProductAdequacyCheck>
     */
    private function citableChecks(): array
    {
        return array_values(array_filter($this->getEligibleChecks(), static fn (ProductAdequacyCheck $check): bool => $check->costs() instanceof \App\Domain\AdequacyReport\ValueObject\CostBreakdown));
    }

    /** Le CGP peut-il préparer une nouvelle version maintenant ? */
    public function canDraft(): bool
    {
        return !$this->getFolder()->isArchived()
            && !$this->getInForceReport() instanceof AdequacyReport
            && $this->isGranted(AdequacyReportVoter::VALIDATE, $this->getFolder());
    }

    /** Le client a-t-il accepté la remise électronique (accusé du DER à jour) ? */
    public function hasElectronicDeliveryConsent(): bool
    {
        return null !== $this->composer->electronicDeliveryConsent($this->getFolder());
    }

    public function canRevoke(AdequacyReport $report): bool
    {
        return $report->isInForce() && $this->isGranted(AdequacyReportVoter::REVOKE, $report);
    }

    public function minRationaleLength(): int
    {
        return AdequacyReport::MIN_RATIONALE_LENGTH;
    }

    public function previewCsrfTokenId(): string
    {
        return AdequacyReportDraftPreviewController::CSRF_TOKEN_ID;
    }

    public function severityIcon(string $severity): string
    {
        return match (MatchSeverity::tryFrom($severity)) {
            MatchSeverity::CONFORME => 'tabler:circle-check',
            MatchSeverity::ATTENTION => 'tabler:alert-triangle',
            MatchSeverity::BLOQUANT => 'tabler:circle-x',
            null => 'tabler:circle-dashed',
        };
    }

    public function severityIconColor(string $severity): string
    {
        return match (MatchSeverity::tryFrom($severity)) {
            MatchSeverity::CONFORME => 'text-emerald-600',
            MatchSeverity::ATTENTION => 'text-amber-600',
            MatchSeverity::BLOQUANT => 'text-rose-600',
            null => 'text-slate-400',
        };
    }

    /** Centimes → « 1 234 € » (montants ronds saisis par le CGP). */
    public function euros(int $cents): string
    {
        return sprintf('%s €', number_format(intdiv($cents, 100), 0, ',', ' '));
    }

    /** Une vérification enregistrée dans l'autre composant rend de nouvelles vérifications citables. */
    #[LiveListener('adequacyCheck:recorded')]
    public function onCheckRecorded(): void
    {
        $this->reportsCache = null;
        $this->preselectSingleEligibleCheck();
    }

    #[LiveAction]
    public function validate(): void
    {
        $this->clearLiveFlash();
        $folder = $this->getFolder();
        $this->denyAccessUnlessGranted(AdequacyReportVoter::VALIDATE, $folder);

        try {
            ($this->validateUseCase)($folder, array_values($this->selectedCheckSlugIds), $this->rationale, $this->periodicReviewChoice());
            $this->selectedCheckSlugIds = [];
            $this->rationale = '';
            $this->periodicReview = '';
            $this->reportsCache = null;
            $this->addLiveFlash('success', 'Rapport d\'adéquation validé et figé. Son PDF est en cours de génération, puis il sera disponible dans l\'espace du client.');
        } catch (\DomainException $e) {
            $this->addLiveFlash('error', $e->getMessage());
        } catch (\Throwable $e) {
            $this->logger->error('Crash lors de la validation d\'un rapport d\'adéquation.', ['folder_slug_id' => $this->folderSlugId, 'exception' => $e]);
            $this->addLiveFlash('error', 'Erreur système lors de la validation.');
        }
    }

    #[LiveAction]
    public function toggleRevoke(): void
    {
        $this->clearLiveFlash();
        $this->isRevoking = !$this->isRevoking;
    }

    #[LiveAction]
    public function revoke(): void
    {
        $this->clearLiveFlash();

        $report = $this->getInForceReport();
        if (!$report instanceof AdequacyReport) {
            $this->addLiveFlash('error', 'Aucun rapport en vigueur à révoquer.');

            return;
        }

        $this->denyAccessUnlessGranted(AdequacyReportVoter::REVOKE, $report);

        try {
            ($this->revokeUseCase)($report, $this->revokeReason);
            $this->isRevoking = false;
            $this->revokeReason = '';
            $this->reportsCache = null;
            $this->prefillFromRevokedReport($report);
            $this->addLiveFlash('success', 'Rapport révoqué. Il reste archivé ; la nouvelle version est préremplie avec la précédente, corrigez puis validez.');
        } catch (\DomainException $e) {
            $this->addLiveFlash('error', $e->getMessage());
        } catch (\Throwable $e) {
            $this->logger->error('Crash lors de la révocation d\'un rapport d\'adéquation.', ['folder_slug_id' => $this->folderSlugId, 'exception' => $e]);
            $this->addLiveFlash('error', 'Erreur système lors de la révocation.');
        }
    }

    private function periodicReviewChoice(): ?bool
    {
        return match ($this->periodicReview) {
            'yes' => true,
            'no' => false,
            default => null,
        };
    }

    /**
     * Cas courant : une seule vérification citable. On la coche d'office (bon réglage par
     * défaut, le CGP peut la décocher).
     */
    private function preselectSingleEligibleCheck(): void
    {
        if ([] !== $this->selectedCheckSlugIds || $this->getInForceReport() instanceof AdequacyReport) {
            return;
        }

        $eligible = $this->citableChecks();
        if (1 === \count($eligible)) {
            $this->selectedCheckSlugIds = [$eligible[0]->slugId];
        }
    }

    /**
     * Après une révocation (souvent pour corriger une phrase), la nouvelle version reprend la
     * justification et les vérifications de la précédente — seulement celles encore citables
     * (une vérification faite sur un profil révoqué depuis disparaît). Rien n'est figé tant
     * que le CGP n'a pas validé : la nouvelle version reste une nouvelle entité scellée.
     */
    private function prefillFromRevokedReport(AdequacyReport $report): void
    {
        $rationale = $report->content['advisorRationale'] ?? '';
        $this->rationale = \is_string($rationale) ? $rationale : '';
        $periodicReview = $report->content['periodicReviewRequired'] ?? null;
        $this->periodicReview = \is_bool($periodicReview) ? ($periodicReview ? 'yes' : 'no') : '';

        $eligible = array_map(static fn (ProductAdequacyCheck $check): string => $check->slugId, $this->citableChecks());
        $cited = [];
        foreach (\is_array($report->content['checks'] ?? null) ? $report->content['checks'] : [] as $check) {
            $slugId = \is_array($check) ? ($check['slugId'] ?? null) : null;
            if (\is_string($slugId) && \in_array($slugId, $eligible, true)) {
                $cited[] = $slugId;
            }
        }

        $this->selectedCheckSlugIds = $cited;
    }
}
