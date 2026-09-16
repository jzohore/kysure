<?php

declare(strict_types=1);

namespace App\Infrastructure\Suitability\Twig\Components;

use App\Application\Suitability\UseCase\RecordAssessmentAnswerUseCase;
use App\Application\Suitability\UseCase\SubmitAssessmentUseCase;
use App\Domain\Suitability\Entity\InvestorProfileAssessment;
use App\Domain\Suitability\Enum\AssessmentAnswerType;
use App\Domain\Suitability\Enum\AssessmentDimension;
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
 * Assistant client, une dimension à la fois (une dizaine d'écrans grand maximum plutôt qu'une
 * question = un écran — regrouper réduit le nombre de clics perçu comme long sans retirer une
 * seule question du §3). Pilote sa progression uniquement sur {@see AssessmentDimension::cases()}
 * et {@see QuestionKey::forDimension()} : ajouter/retirer une question ne demande aucun
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
    public int $currentDimensionIndex = 0;

    /**
     * Valeurs brutes (chaînes) en cours de saisie pour l'écran courant, indexées par
     * {@see QuestionKey::value}. Toutes les questions sauf {@see QuestionKey::EXPERIENCE_PRODUCTS_HELD}
     * (choix multiple, porté séparément par {@see self::$productsHeld}).
     *
     * @var array<string, string|null>
     */
    #[LiveProp(writable: true)]
    public array $answers = [];

    /** @var list<string> */
    #[LiveProp(writable: true)]
    public array $productsHeld = [];

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
        $this->syncAnswersFromStored();
    }

    public function getCurrentDimension(): AssessmentDimension
    {
        return AssessmentDimension::cases()[$this->currentDimensionIndex];
    }

    /**
     * @return list<QuestionKey>
     */
    public function getCurrentQuestions(): array
    {
        return QuestionKey::forDimension($this->getCurrentDimension());
    }

    public function getTotalDimensions(): int
    {
        return \count(AssessmentDimension::cases());
    }

    public function getProgressPercent(): int
    {
        return (int) round(($this->currentDimensionIndex + 1) / $this->getTotalDimensions() * 100);
    }

    #[LiveAction]
    public function previous(): void
    {
        $this->clearLiveFlash();

        if ($this->currentDimensionIndex > 0) {
            --$this->currentDimensionIndex;
            $this->syncAnswersFromStored();
        }
    }

    #[LiveAction]
    public function next(): ?RedirectResponse
    {
        $this->clearLiveFlash();

        $assessment = $this->loadAssessment();
        $questions = $this->getCurrentQuestions();

        foreach ($questions as $key) {
            $value = $this->castRawValue($key);
            if ($key->isRequired() && !$this->isAnswered($key, $value)) {
                $this->addLiveFlash('error', 'Merci de répondre à toutes les questions avant de continuer.');

                return null;
            }
        }

        foreach ($questions as $key) {
            ($this->recordAnswerUseCase)($assessment, $key, $this->castRawValue($key), $this->currentClient());
        }

        if ($this->currentDimensionIndex + 1 >= $this->getTotalDimensions()) {
            ($this->submitUseCase)($assessment);

            $this->logger->info('Questionnaire profil investisseur soumis.', ['assessment_slug_id' => $assessment->slugId]);

            return new RedirectResponse($this->urlGenerator->generate('app_portal_investor_profile_done'));
        }

        ++$this->currentDimensionIndex;
        $this->syncAnswersFromStored();

        return null;
    }

    private function castRawValue(QuestionKey $key): mixed
    {
        if (QuestionKey::EXPERIENCE_PRODUCTS_HELD === $key) {
            return $this->productsHeld;
        }

        $raw = $this->answers[$key->value] ?? null;

        return match ($key->answerType()) {
            AssessmentAnswerType::SINGLE_CHOICE_INT, AssessmentAnswerType::INTEGER => null !== $raw && '' !== $raw ? (int) $raw : null,
            AssessmentAnswerType::SINGLE_CHOICE_STRING, AssessmentAnswerType::TEXT => null !== $raw && '' !== $raw ? $raw : null,
            AssessmentAnswerType::DECIMAL => null !== $raw && '' !== $raw ? (float) $raw : null,
            AssessmentAnswerType::BOOLEAN => match ($raw) {
                '1' => true,
                '0' => false,
                default => null,
            },
            AssessmentAnswerType::MULTI_CHOICE_STRING => $this->productsHeld,
        };
    }

    private function isAnswered(QuestionKey $key, mixed $value): bool
    {
        return match ($key->answerType()) {
            AssessmentAnswerType::MULTI_CHOICE_STRING => [] !== $value,
            default => null !== $value,
        };
    }

    private function syncAnswersFromStored(): void
    {
        $assessment = $this->loadAssessment();

        foreach ($this->getCurrentQuestions() as $key) {
            $stored = $assessment->getAnswerValue($key);

            if (QuestionKey::EXPERIENCE_PRODUCTS_HELD === $key) {
                /** @var list<string> $held */
                $held = \is_array($stored) ? $stored : [];
                $this->productsHeld = $held;

                continue;
            }

            $this->answers[$key->value] = match (true) {
                null === $stored => null,
                \is_bool($stored) => $stored ? '1' : '0',
                default => (string) $stored,
            };
        }
    }

    private function loadAssessment(): InvestorProfileAssessment
    {
        $assessment = $this->assessmentRepository->findOneBySlugId($this->assessmentSlugId);

        if (!$assessment instanceof InvestorProfileAssessment || $assessment->client !== $this->currentClient()) {
            throw $this->createNotFoundException('Questionnaire introuvable.');
        }

        return $assessment;
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
