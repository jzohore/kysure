<?php

declare(strict_types=1);

namespace App\Infrastructure\Suitability\Twig\Components;

use App\Application\Suitability\DTO\Response\InvestorProfileComparisonResponse;
use App\Application\Suitability\UseCase\InvestorProfileComparisonAssembler;
use App\Application\Suitability\UseCase\RevokeInvestorProfileUseCase;
use App\Application\Suitability\UseCase\ValidateInvestorProfileUseCase;
use App\Domain\Compliance\Entity\ComplianceFolder;
use App\Domain\Compliance\Entity\ValidatedMeetingReport;
use App\Domain\Compliance\Enum\AdvisoryRiskProfile;
use App\Domain\Compliance\Repository\ComplianceFolderRepositoryInterface;
use App\Domain\Compliance\Repository\ValidatedMeetingReportRepositoryInterface;
use App\Domain\Suitability\Entity\InvestorProfileAssessment;
use App\Domain\Suitability\Entity\ValidatedInvestorProfile;
use App\Domain\Suitability\Enum\InvestorProfileLevel;
use App\Domain\Suitability\Repository\InvestorProfileAssessmentRepositoryInterface;
use App\Domain\Suitability\Repository\ValidatedInvestorProfileRepositoryInterface;
use App\Domain\Suitability\Service\AdvisoryProfileDivergenceDetector;
use App\Domain\Suitability\Service\TraderWithoutSafetyNetDetector;
use App\Domain\User\Entity\Client;
use App\Domain\User\Repository\ClientRepositoryInterface;
use App\Domain\Workspace\Service\CurrentWorkspaceProvider;
use App\Infrastructure\Shared\Component\LiveFlashTrait;
use App\Infrastructure\Suitability\Voter\InvestorProfileVoter;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Webmozart\Assert\Assert;

/**
 * Écran de revue conseiller : restitue le profil investisseur calculé (figé si déjà validé,
 * brouillon sinon), et porte les actions de validation/révocation. Pattern calqué sur
 * {@see \App\Infrastructure\Compliance\Twig\Components\AiReportDisplayComponent}.
 *
 * Toute lecture (assessment, profil validé) est scopée au workspace courant : un client peut
 * être suivi par plusieurs cabinets à la fois, chacun avec son propre historique de
 * validation — ce composant ne doit jamais restituer le travail d'un cabinet concurrent.
 */
#[AsLiveComponent(
    name: 'InvestorProfileReviewComponent',
    template: 'components/Compliance/InvestorProfileReviewComponent.html.twig',
)]
class InvestorProfileReviewComponent extends AbstractController
{
    use DefaultActionTrait;
    use LiveFlashTrait;

    #[LiveProp]
    public string $clientSlugId;

    #[LiveProp(writable: true)]
    public bool $isOverriding = false;

    #[LiveProp(writable: true)]
    public bool $isRevoking = false;

    #[LiveProp(writable: true)]
    public string $overriddenLevel = '';

    #[LiveProp(writable: true)]
    public string $overrideReason = '';

    #[LiveProp(writable: true)]
    public string $revokeReason = '';

    private ?Client $clientCache = null;
    private ?InvestorProfileAssessment $latestAssessmentCache = null;
    private bool $latestAssessmentLoaded = false;
    private ?ValidatedInvestorProfile $inForceProfileCache = null;
    private bool $inForceProfileLoaded = false;
    private ?AdvisoryRiskProfile $advisoryRiskProfileCache = null;
    private bool $advisoryRiskProfileLoaded = false;
    /** @var list<ValidatedInvestorProfile>|null */
    private ?array $historyCache = null;

    public function __construct(
        private readonly ClientRepositoryInterface $clientRepository,
        private readonly CurrentWorkspaceProvider $workspaceProvider,
        private readonly InvestorProfileAssessmentRepositoryInterface $assessmentRepository,
        private readonly ValidatedInvestorProfileRepositoryInterface $profileRepository,
        private readonly ComplianceFolderRepositoryInterface $folderRepository,
        private readonly ValidatedMeetingReportRepositoryInterface $meetingReportRepository,
        private readonly ValidateInvestorProfileUseCase $validateInvestorProfileUseCase,
        private readonly RevokeInvestorProfileUseCase $revokeInvestorProfileUseCase,
        private readonly TraderWithoutSafetyNetDetector $traderWithoutSafetyNetDetector,
        private readonly AdvisoryProfileDivergenceDetector $divergenceDetector,
        private readonly InvestorProfileComparisonAssembler $comparisonAssembler,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getClient(): Client
    {
        return $this->clientCache ??= $this->requireClient();
    }

    public function getLatestAssessment(): ?InvestorProfileAssessment
    {
        if (!$this->latestAssessmentLoaded) {
            $this->latestAssessmentCache = $this->assessmentRepository->findLatestSubmittedForClient($this->getClient(), $this->workspaceProvider->getWorkspace());
            $this->latestAssessmentLoaded = true;
        }

        return $this->latestAssessmentCache;
    }

    public function getInForceProfile(): ?ValidatedInvestorProfile
    {
        if (!$this->inForceProfileLoaded) {
            $this->inForceProfileCache = $this->profileRepository->findInForceByClient($this->getClient(), $this->workspaceProvider->getWorkspace());
            $this->inForceProfileLoaded = true;
        }

        return $this->inForceProfileCache;
    }

    public function hasSubmittedAssessment(): bool
    {
        return $this->getLatestAssessment() instanceof InvestorProfileAssessment;
    }

    public function isValidated(): bool
    {
        return $this->getInForceProfile() instanceof ValidatedInvestorProfile;
    }

    /**
     * Le résultat du moteur de scoring à afficher : celui figé si un profil est validé, sinon
     * le brouillon calculé à la soumission du questionnaire.
     *
     * @return array<string, mixed>|null
     */
    public function getScoreSnapshot(): ?array
    {
        $profile = $this->getInForceProfile();
        if ($profile instanceof ValidatedInvestorProfile) {
            /** @var array<string, mixed> $snapshot */
            $snapshot = $profile->content['scoreSnapshot'];

            return $snapshot;
        }

        return $this->getLatestAssessment()?->scoreSnapshot;
    }

    public function getRetainedProfileLevel(): ?int
    {
        $profile = $this->getInForceProfile();
        if ($profile instanceof ValidatedInvestorProfile) {
            return $profile->retainedProfileLevel();
        }

        $snapshot = $this->getScoreSnapshot();

        return null !== $snapshot ? (int) $snapshot['finalProfile'] : null;
    }

    public function getRetainedProfileLabel(): ?string
    {
        $level = $this->getRetainedProfileLevel();

        return null !== $level ? InvestorProfileLevel::from($level)->getLabel() : null;
    }

    public function isProfileOverridden(): bool
    {
        return $this->getInForceProfile()?->isOverridden() ?? false;
    }

    /**
     * Vrai si le profil (calculé ou déjà validé) cumule une appétence au risque déclarée très
     * forte et une capacité à subir des pertes très faible (« trader sans filet », décision
     * lot 5) : déjà plafonné dans le score final, affiché ici comme mise en garde explicite
     * pour le CGP avant/après validation.
     */
    public function hasHighRiskLowCapacityMismatch(): bool
    {
        return $this->traderWithoutSafetyNetDetector->detect($this->getScoreSnapshot() ?? []);
    }

    /**
     * Le profil de risque perçu à l'entretien (rapport de synthèse validé en vigueur pour le
     * dossier actif de CE cabinet), ou `null` si aucun rapport n'a encore été validé.
     */
    public function getAdvisoryRiskProfile(): ?AdvisoryRiskProfile
    {
        if (!$this->advisoryRiskProfileLoaded) {
            $this->advisoryRiskProfileLoaded = true;
            $folder = $this->folderRepository->findActiveForClientAndWorkspace($this->getClient(), $this->workspaceProvider->getWorkspace());

            if ($folder instanceof ComplianceFolder) {
                $report = $this->meetingReportRepository->findInForceByFolder($folder);
                if ($report instanceof ValidatedMeetingReport) {
                    $this->advisoryRiskProfileCache = AdvisoryRiskProfile::fromLabel($report->content['riskProfile'] ?? null);
                }
            }
        }

        return $this->advisoryRiskProfileCache;
    }

    /**
     * Vrai si le profil perçu à l'entretien diverge significativement du profil retenu du
     * questionnaire (décision lot 5 : simple alerte, jamais de blocage — le profil du
     * questionnaire fait foi, l'entretien reste une tendance perçue par le CGP).
     */
    public function hasAdvisoryProfileDivergence(): bool
    {
        $advisoryProfile = $this->getAdvisoryRiskProfile();
        $retainedLevel = $this->getRetainedProfileLevel();

        if (!$advisoryProfile instanceof AdvisoryRiskProfile || null === $retainedLevel) {
            return false;
        }

        return $this->divergenceDetector->detect($advisoryProfile, $retainedLevel);
    }

    /**
     * Historique complet des versions du profil investisseur pour ce client, dans CE cabinet
     * uniquement, du plus récent au plus ancien.
     *
     * @return list<ValidatedInvestorProfile>
     */
    public function getHistory(): array
    {
        return $this->historyCache ??= $this->profileRepository->findAllByClient($this->getClient(), $this->workspaceProvider->getWorkspace());
    }

    public function hasMultipleVersions(): bool
    {
        return \count($this->getHistory()) > 1;
    }

    /**
     * Comparatif visuel entre les deux versions les plus récentes (cas typique : révocation
     * puis revalidation après un changement de situation du client) — `null` s'il n'y a rien à
     * comparer.
     */
    public function getComparison(): ?InvestorProfileComparisonResponse
    {
        $history = $this->getHistory();

        if (\count($history) < 2) {
            return null;
        }

        return $this->comparisonAssembler->assemble(older: $history[1], newer: $history[0]);
    }

    public function canValidate(): bool
    {
        $assessment = $this->getLatestAssessment();

        return $assessment instanceof InvestorProfileAssessment && $this->isGranted(InvestorProfileVoter::VALIDATE, $assessment);
    }

    public function canRevoke(): bool
    {
        $assessment = $this->getLatestAssessment();

        return $assessment instanceof InvestorProfileAssessment && $this->isGranted(InvestorProfileVoter::REVOKE, $assessment);
    }

    #[LiveAction]
    public function toggleOverride(): void
    {
        $this->clearLiveFlash();
        $this->isOverriding = !$this->isOverriding;
    }

    #[LiveAction]
    public function toggleRevoke(): void
    {
        $this->clearLiveFlash();
        $this->isRevoking = !$this->isRevoking;
    }

    #[LiveAction]
    public function validate(): void
    {
        $this->clearLiveFlash();

        $assessment = $this->getLatestAssessment();
        if (!$assessment instanceof InvestorProfileAssessment) {
            $this->addLiveFlash('error', 'Aucun questionnaire soumis à valider.');

            return;
        }

        $this->denyAccessUnlessGranted(InvestorProfileVoter::VALIDATE, $assessment);

        $overriddenLevel = $this->isOverriding && '' !== $this->overriddenLevel ? (int) $this->overriddenLevel : null;

        try {
            ($this->validateInvestorProfileUseCase)($assessment, $overriddenLevel, $this->overrideReason);
            $this->isOverriding = false;
            $this->overriddenLevel = '';
            $this->overrideReason = '';
            $this->refreshCaches();
            $this->addLiveFlash('success', 'Profil investisseur validé : il est désormais figé.');
        } catch (\DomainException $e) {
            $this->addLiveFlash('error', $e->getMessage());
        } catch (\Throwable) {
            $this->logger->error('Crash lors de la validation du profil investisseur.', ['client_slug_id' => $this->clientSlugId]);
            $this->addLiveFlash('error', 'Erreur système lors de la validation.');
        }
    }

    #[LiveAction]
    public function revoke(): void
    {
        $this->clearLiveFlash();

        $assessment = $this->getLatestAssessment();
        if ($assessment instanceof InvestorProfileAssessment) {
            $this->denyAccessUnlessGranted(InvestorProfileVoter::REVOKE, $assessment);
        }

        $profile = $this->getInForceProfile();
        if (!$profile instanceof ValidatedInvestorProfile) {
            $this->addLiveFlash('error', 'Aucun profil validé à révoquer.');

            return;
        }

        try {
            ($this->revokeInvestorProfileUseCase)($profile->slugId, $this->revokeReason);
            $this->isRevoking = false;
            $this->revokeReason = '';
            $this->refreshCaches();
            $this->addLiveFlash('success', 'Profil investisseur révoqué. Une nouvelle version pourra être validée après un nouveau passage du questionnaire.');
        } catch (\DomainException $e) {
            $this->addLiveFlash('error', $e->getMessage());
        } catch (\Throwable) {
            $this->logger->error('Crash lors de la révocation du profil investisseur.', ['client_slug_id' => $this->clientSlugId]);
            $this->addLiveFlash('error', 'Erreur système lors de la révocation.');
        }
    }

    private function requireClient(): Client
    {
        $client = $this->clientRepository->findOneBySlugIdAndWorkspace($this->clientSlugId, $this->workspaceProvider->getWorkspace());
        Assert::notNull($client, 'Client introuvable.');

        return $client;
    }

    private function refreshCaches(): void
    {
        $this->latestAssessmentCache = null;
        $this->latestAssessmentLoaded = false;
        $this->inForceProfileCache = null;
        $this->inForceProfileLoaded = false;
        $this->historyCache = null;
    }
}
