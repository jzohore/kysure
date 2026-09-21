<?php

declare(strict_types=1);

namespace App\Tests\Application\Suitability;

use App\Application\Suitability\UseCase\SubmitAssessmentUseCase;
use App\Domain\Database\TransactionManagerInterface;
use App\Domain\Suitability\Entity\InvestorProfileAssessment;
use App\Domain\Suitability\Enum\AnswerSource;
use App\Domain\Suitability\Enum\InvestmentHorizon;
use App\Domain\Suitability\Enum\KnowledgeLevel;
use App\Domain\Suitability\Enum\LossReaction;
use App\Domain\Suitability\Enum\ProductFamily;
use App\Domain\Suitability\Enum\QuestionKey;
use App\Domain\Suitability\Enum\SustainabilityPreference;
use App\Domain\Suitability\Enum\TransactionFrequency;
use App\Domain\Suitability\Event\InvestorProfileAssessmentSubmittedEvent;
use App\Domain\Suitability\Repository\InvestorProfileAssessmentRepositoryInterface;
use App\Domain\Suitability\Service\AssessmentAnswersAssembler;
use App\Domain\Suitability\Service\ScoringEngineV1;
use App\Domain\User\Entity\Client;
use App\Domain\Workspace\Entity\Workspace;
use App\Tests\Application\ReflectionHelperTrait;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Uid\Uuid;

final class SubmitAssessmentUseCaseTest extends TestCase
{
    use ReflectionHelperTrait;

    private InvestorProfileAssessment $assessment;

    protected function setUp(): void
    {
        $workspace = $this->createEntityState(Workspace::class, ['slugId' => 'wrk_1', 'name' => 'Cabinet']);
        $client = $this->createEntityState(Client::class, ['id' => Uuid::v7(), 'slugId' => 'cli_1', 'email' => 'jean@example.com']);
        $this->assessment = InvestorProfileAssessment::create($workspace, $client);

        foreach ($this->validAnswers() as $key => $value) {
            $this->assessment->recordAnswer(QuestionKey::from($key), $value, AnswerSource::CLIENT, $client->id);
        }
    }

    public function testComputesTheScoreFreezesTheAssessmentAndDispatchesTheEvent(): void
    {
        $assessmentRepo = $this->createMock(InvestorProfileAssessmentRepositoryInterface::class);
        $assessmentRepo->expects(self::once())->method('save')->with($this->assessment);

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::once())->method('dispatch')->with(
            self::isInstanceOf(InvestorProfileAssessmentSubmittedEvent::class),
        );

        $useCase = new SubmitAssessmentUseCase($assessmentRepo, new AssessmentAnswersAssembler(), new ScoringEngineV1(), $this->passThroughTransactionManager(), $dispatcher);
        ($useCase)($this->assessment);

        self::assertTrue($this->assessment->isSubmitted());
        self::assertNotNull($this->assessment->scoreSnapshot);
        self::assertSame('suitability_engine_v1', $this->assessment->scoreSnapshot['engineVersion']);
    }

    public function testIsIdempotentOnAnAlreadySubmittedAssessment(): void
    {
        // Amène l'assessment à l'état soumis, hors de la portée vérifiée par ce test.
        $bootstrapRepo = $this->createStub(InvestorProfileAssessmentRepositoryInterface::class);
        $bootstrapDispatcher = $this->createStub(EventDispatcherInterface::class);
        $bootstrap = new SubmitAssessmentUseCase($bootstrapRepo, new AssessmentAnswersAssembler(), new ScoringEngineV1(), $this->passThroughTransactionManager(), $bootstrapDispatcher);
        ($bootstrap)($this->assessment);

        $repo = $this->createMock(InvestorProfileAssessmentRepositoryInterface::class);
        $repo->expects(self::never())->method('save');
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::never())->method('dispatch');

        $useCase = new SubmitAssessmentUseCase($repo, new AssessmentAnswersAssembler(), new ScoringEngineV1(), $this->passThroughTransactionManager(), $dispatcher);
        ($useCase)($this->assessment);
    }

    private function passThroughTransactionManager(): TransactionManagerInterface
    {
        $transactionManager = $this->createStub(TransactionManagerInterface::class);
        $transactionManager->method('transactional')->willReturnCallback(static fn (callable $cb) => $cb());

        return $transactionManager;
    }

    /**
     * @return array<string, mixed>
     */
    private function validAnswers(): array
    {
        return [
            QuestionKey::KNOWLEDGE_OPCVM_ETF->value => KnowledgeLevel::BONNE->value,
            QuestionKey::KNOWLEDGE_TITRES_VIFS->value => KnowledgeLevel::LIMITEE->value,
            QuestionKey::KNOWLEDGE_ASSURANCE_VIE->value => KnowledgeLevel::EXPERTISE->value,
            QuestionKey::KNOWLEDGE_IMMOBILIER_SCPI->value => KnowledgeLevel::INTERMEDIAIRE->value,
            QuestionKey::KNOWLEDGE_PRODUITS_COMPLEXES->value => KnowledgeLevel::AUCUNE->value,

            QuestionKey::EXPERIENCE_PRODUCTS_HELD->value => [ProductFamily::OPCVM_ETF->value],
            QuestionKey::EXPERIENCE_TRANSACTION_FREQUENCY->value => TransactionFrequency::OCCASIONNELLE->value,
            QuestionKey::EXPERIENCE_APPROXIMATE_AMOUNT->value => 1000,
            QuestionKey::EXPERIENCE_YEARS->value => 2,
            QuestionKey::EXPERIENCE_TRANSACTION_COUNT->value => 5,
            QuestionKey::EXPERIENCE_HAS_EXPERIENCED_LOSSES->value => false,

            QuestionKey::TOLERANCE_REACTION_MINUS_10->value => LossReaction::NE_FAIT_RIEN->value,
            QuestionKey::TOLERANCE_REACTION_MINUS_20->value => LossReaction::NE_FAIT_RIEN->value,
            QuestionKey::TOLERANCE_REACTION_SIGNIFICANT_LOSS->value => LossReaction::REDUIT_LA_POSITION->value,

            QuestionKey::CAPACITY_ANNUAL_INCOME->value => 50000,
            QuestionKey::CAPACITY_ANNUAL_EXPENSES->value => 30000,
            QuestionKey::CAPACITY_NET_WORTH->value => 100000,
            QuestionKey::CAPACITY_AVAILABLE_LIQUIDITY->value => 20000,
            QuestionKey::CAPACITY_AMOUNT_TO_INVEST->value => 5000,
            QuestionKey::CAPACITY_HORIZON->value => InvestmentHorizon::DE_5_A_8_ANS->value,

            QuestionKey::SUSTAINABILITY_PREFERENCE->value => SustainabilityPreference::SANS_PREFERENCE->value,
            QuestionKey::SUSTAINABILITY_CONSTRAINTS->value => null,
        ];
    }
}
