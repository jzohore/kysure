<?php

declare(strict_types=1);

namespace App\Infrastructure\Suitability\Twig\Components;

use App\Application\Suitability\UseCase\RevokeInvestorProfileUseCase;
use App\Application\Suitability\UseCase\ValidateInvestorProfileUseCase;
use App\Domain\Suitability\Entity\InvestorProfileAssessment;
use App\Domain\Suitability\Entity\ValidatedInvestorProfile;
use App\Domain\Suitability\Enum\InvestorProfileLevel;
use App\Domain\Suitability\Repository\InvestorProfileAssessmentRepositoryInterface;
use App\Domain\Suitability\Repository\ValidatedInvestorProfileRepositoryInterface;
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

    public function __construct(
        private readonly ClientRepositoryInterface $clientRepository,
        private readonly CurrentWorkspaceProvider $workspaceProvider,
        private readonly InvestorProfileAssessmentRepositoryInterface $assessmentRepository,
        private readonly ValidatedInvestorProfileRepositoryInterface $profileRepository,
        private readonly ValidateInvestorProfileUseCase $validateInvestorProfileUseCase,
        private readonly RevokeInvestorProfileUseCase $revokeInvestorProfileUseCase,
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
            $this->latestAssessmentCache = $this->assessmentRepository->findLatestSubmittedForClient($this->getClient());
            $this->latestAssessmentLoaded = true;
        }

        return $this->latestAssessmentCache;
    }

    public function getInForceProfile(): ?ValidatedInvestorProfile
    {
        if (!$this->inForceProfileLoaded) {
            $this->inForceProfileCache = $this->profileRepository->findInForceByClient($this->getClient());
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
    }
}
