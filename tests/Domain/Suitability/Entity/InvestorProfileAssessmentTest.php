<?php

declare(strict_types=1);

namespace App\Tests\Domain\Suitability\Entity;

use App\Domain\Suitability\Entity\InvestorProfileAssessment;
use App\Domain\Suitability\Enum\AnswerSource;
use App\Domain\Suitability\Enum\AssessmentStatus;
use App\Domain\Suitability\Enum\QuestionKey;
use App\Domain\User\Entity\Client;
use App\Domain\Workspace\Entity\Workspace;
use App\Tests\Application\ReflectionHelperTrait;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class InvestorProfileAssessmentTest extends TestCase
{
    use ReflectionHelperTrait;

    private Workspace $workspace;
    private Client $client;

    protected function setUp(): void
    {
        $this->workspace = $this->createEntityState(Workspace::class, ['slugId' => 'wrk_1', 'name' => 'Cabinet']);
        $this->client = $this->createEntityState(Client::class, [
            'id' => Uuid::v7(),
            'slugId' => 'cli_1',
            'email' => 'jean@example.com',
            'firstName' => 'Jean',
            'lastName' => 'Dupont',
        ]);
    }

    public function testCreateStartsAsAnEmptyDraft(): void
    {
        $assessment = InvestorProfileAssessment::create($this->workspace, $this->client);

        self::assertSame(AssessmentStatus::DRAFT, $assessment->status);
        self::assertFalse($assessment->isSubmitted());
        self::assertFalse($assessment->isComplete());
        self::assertCount(\count(QuestionKey::cases()), $assessment->missingAnswers());
        self::assertStringStartsWith('ipa_', $assessment->slugId);
    }

    public function testRecordAnswerStoresTheTripletAndIsIdempotentOnReplay(): void
    {
        $assessment = InvestorProfileAssessment::create($this->workspace, $this->client);

        $assessment->recordAnswer(QuestionKey::CAPACITY_ANNUAL_INCOME, 45000, AnswerSource::CLIENT, $this->client->id);
        self::assertSame(45000, $assessment->getAnswerValue(QuestionKey::CAPACITY_ANNUAL_INCOME));
        self::assertTrue($assessment->hasAnswer(QuestionKey::CAPACITY_ANNUAL_INCOME));

        // Rejouer la même réponse (retour arrière du client) la remplace, sans doublon.
        $assessment->recordAnswer(QuestionKey::CAPACITY_ANNUAL_INCOME, 47000, AnswerSource::CLIENT, $this->client->id);
        self::assertSame(47000, $assessment->getAnswerValue(QuestionKey::CAPACITY_ANNUAL_INCOME));
        self::assertCount(1, $assessment->answersAsMap());
    }

    public function testMissingAnswersShrinksAsQuestionsAreAnswered(): void
    {
        $assessment = InvestorProfileAssessment::create($this->workspace, $this->client);
        $totalQuestions = \count(QuestionKey::cases());

        $assessment->recordAnswer(QuestionKey::SUSTAINABILITY_PREFERENCE, 'sans_preference', AnswerSource::CLIENT, $this->client->id);

        self::assertCount($totalQuestions - 1, $assessment->missingAnswers());
        self::assertNotContains(QuestionKey::SUSTAINABILITY_PREFERENCE, $assessment->missingAnswers());
    }

    public function testSubmitRejectsAnIncompleteAssessment(): void
    {
        $assessment = InvestorProfileAssessment::create($this->workspace, $this->client);
        $assessment->recordAnswer(QuestionKey::SUSTAINABILITY_PREFERENCE, 'sans_preference', AnswerSource::CLIENT, $this->client->id);

        $this->expectException(\InvalidArgumentException::class);

        $assessment->submit(['finalProfile' => 4]);
    }

    public function testSubmitFreezesTheStatusAndSnapshot(): void
    {
        $assessment = $this->completeAssessment();

        $assessment->submit(['finalProfile' => 4, 'engineVersion' => 'suitability_engine_v1']);

        self::assertTrue($assessment->isSubmitted());
        self::assertSame(AssessmentStatus::SUBMITTED, $assessment->status);
        self::assertNotNull($assessment->submittedAt);
        self::assertSame(['finalProfile' => 4, 'engineVersion' => 'suitability_engine_v1'], $assessment->scoreSnapshot);
    }

    public function testSubmitIsIdempotentAndKeepsTheFirstSnapshot(): void
    {
        $assessment = $this->completeAssessment();

        $assessment->submit(['finalProfile' => 4]);
        $firstSubmittedAt = $assessment->submittedAt;

        // Rejouer la soumission (double clic, retry réseau) ne doit rien changer.
        $assessment->submit(['finalProfile' => 99]);

        self::assertSame(['finalProfile' => 4], $assessment->scoreSnapshot);
        self::assertSame($firstSubmittedAt, $assessment->submittedAt);
    }

    public function testRecordAnswerIsForbiddenAfterSubmission(): void
    {
        $assessment = $this->completeAssessment();
        $assessment->submit(['finalProfile' => 4]);

        $this->expectException(\DomainException::class);

        $assessment->recordAnswer(QuestionKey::SUSTAINABILITY_PREFERENCE, 'interesse', AnswerSource::CLIENT, $this->client->id);
    }

    private function completeAssessment(): InvestorProfileAssessment
    {
        $assessment = InvestorProfileAssessment::create($this->workspace, $this->client);

        foreach (QuestionKey::cases() as $key) {
            $assessment->recordAnswer($key, 'reponse', AnswerSource::CLIENT, $this->client->id);
        }

        return $assessment;
    }
}
