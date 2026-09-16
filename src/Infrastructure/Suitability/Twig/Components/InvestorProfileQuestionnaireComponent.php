<?php

declare(strict_types=1);

namespace App\Infrastructure\Suitability\Twig\Components;

use App\Application\Suitability\UseCase\RecordAssessmentAnswerUseCase;
use App\Application\Suitability\UseCase\SubmitAssessmentUseCase;
use App\Domain\Suitability\Entity\InvestorProfileAssessment;
use App\Domain\Suitability\Enum\AssessmentAnswerType;
use App\Domain\Suitability\Enum\QuestionKey;
use App\Domain\Suitability\Repository\InvestorProfileAssessmentRepositoryInterface;
use App\Domain\User\Entity\Client;
use App\Infrastructure\Shared\Component\LiveFlashTrait;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Assistant client, une question à la fois (§3 du cahier des charges). Pilote sa progression
 * uniquement sur {@see QuestionKey::cases()} : ajouter/retirer une question ne demande aucun
 * changement ici, seulement dans l'enum. Chaque réponse est sauvegardée immédiatement
 * (RecordAssessmentAnswerUseCase) pour permettre la reprise de session.
 */
#[AsLiveComponent(
    name: 'InvestorProfileQuestionnaireComponent',
    template: 'components/User/Client/InvestorProfileQuestionnaireComponent.html.twig',
    route: 'portal_ux_live_component',
)]
class InvestorProfileQuestionnaireComponent extends AbstractController
{
    use DefaultActionTrait;
    use LiveFlashTrait;

    #[LiveProp]
    public string $assessmentSlugId;

    #[LiveProp(writable: true)]
    public int $currentIndex = 0;

    #[LiveProp(writable: true)]
    public ?string $rawValue = null;

    /** @var list<string> */
    #[LiveProp(writable: true)]
    public array $rawMultiValues = [];

    public function __construct(
        private readonly InvestorProfileAssessmentRepositoryInterface $assessmentRepository,
        private readonly RecordAssessmentAnswerUseCase $recordAnswerUseCase,
        private readonly SubmitAssessmentUseCase $submitUseCase,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function mount(string $assessmentSlugId): void
    {
        $this->assessmentSlugId = $assessmentSlugId;
        $this->syncRawValueFromCurrentAnswer();
    }

    public function getCurrentQuestion(): QuestionKey
    {
        return $this->orderedQuestions()[$this->currentIndex];
    }

    public function getTotalQuestions(): int
    {
        return \count($this->orderedQuestions());
    }

    public function getProgressPercent(): int
    {
        return (int) round(($this->currentIndex + 1) / $this->getTotalQuestions() * 100);
    }

    public function getAnswerTypeName(): string
    {
        return $this->getCurrentQuestion()->answerType()->name;
    }

    #[LiveAction]
    public function previous(): void
    {
        $this->clearLiveFlash();

        if ($this->currentIndex > 0) {
            --$this->currentIndex;
            $this->syncRawValueFromCurrentAnswer();
        }
    }

    #[LiveAction]
    public function next(): ?RedirectResponse
    {
        $this->clearLiveFlash();

        $assessment = $this->loadAssessment();
        $key = $this->getCurrentQuestion();
        $value = $this->castRawValue($key);

        if ($key->isRequired() && !$this->isAnswered($key, $value)) {
            $this->addLiveFlash('error', 'Merci de répondre avant de continuer.');

            return null;
        }

        ($this->recordAnswerUseCase)($assessment, $key, $value, $this->currentClient());

        if ($this->currentIndex + 1 >= $this->getTotalQuestions()) {
            ($this->submitUseCase)($assessment);

            $this->logger->info('Questionnaire profil investisseur soumis.', ['assessment_slug_id' => $assessment->slugId]);

            return new RedirectResponse($this->urlGenerator->generate('app_portal_investor_profile_done'));
        }

        ++$this->currentIndex;
        $this->syncRawValueFromCurrentAnswer();

        return null;
    }

    private function castRawValue(QuestionKey $key): mixed
    {
        return match ($key->answerType()) {
            AssessmentAnswerType::SINGLE_CHOICE_INT => null !== $this->rawValue && '' !== $this->rawValue ? (int) $this->rawValue : null,
            AssessmentAnswerType::SINGLE_CHOICE_STRING, AssessmentAnswerType::TEXT => '' !== $this->rawValue ? $this->rawValue : null,
            AssessmentAnswerType::MULTI_CHOICE_STRING => $this->rawMultiValues,
            AssessmentAnswerType::INTEGER => null !== $this->rawValue && '' !== $this->rawValue ? (int) $this->rawValue : null,
            AssessmentAnswerType::DECIMAL => null !== $this->rawValue && '' !== $this->rawValue ? (float) $this->rawValue : null,
            AssessmentAnswerType::BOOLEAN => match ($this->rawValue) {
                '1' => true,
                '0' => false,
                default => null,
            },
        };
    }

    private function isAnswered(QuestionKey $key, mixed $value): bool
    {
        return match ($key->answerType()) {
            AssessmentAnswerType::MULTI_CHOICE_STRING => [] !== $value,
            default => null !== $value,
        };
    }

    private function syncRawValueFromCurrentAnswer(): void
    {
        $assessment = $this->loadAssessment();
        $key = $this->getCurrentQuestion();
        $stored = $assessment->getAnswerValue($key);

        if (AssessmentAnswerType::MULTI_CHOICE_STRING === $key->answerType()) {
            /** @var list<string> $multi */
            $multi = \is_array($stored) ? $stored : [];
            $this->rawMultiValues = $multi;
            $this->rawValue = null;

            return;
        }

        $this->rawMultiValues = [];
        $this->rawValue = match (true) {
            null === $stored => null,
            \is_bool($stored) => $stored ? '1' : '0',
            default => (string) $stored,
        };
    }

    private function loadAssessment(): InvestorProfileAssessment
    {
        $assessment = $this->assessmentRepository->findOneBySlugId($this->assessmentSlugId);

        if (!$assessment instanceof InvestorProfileAssessment || $assessment->client !== $this->currentClient()) {
            throw $this->createNotFoundException('Questionnaire introuvable.');
        }

        return $assessment;
    }

    /**
     * @return list<QuestionKey>
     */
    private function orderedQuestions(): array
    {
        return QuestionKey::cases();
    }

    private function currentClient(): Client
    {
        $client = $this->getUser();

        if (!$client instanceof Client) {
            throw $this->createAccessDeniedException('Espace réservé aux clients.');
        }

        return $client;
    }
}
