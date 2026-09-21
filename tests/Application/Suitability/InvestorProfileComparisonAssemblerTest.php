<?php

declare(strict_types=1);

namespace App\Tests\Application\Suitability;

use App\Application\Suitability\UseCase\InvestorProfileComparisonAssembler;
use App\Application\Suitability\UseCase\InvestorProfileSynthesisAssembler;
use App\Domain\Suitability\Entity\InvestorProfileAssessment;
use App\Domain\Suitability\Entity\ValidatedInvestorProfile;
use App\Domain\Suitability\Enum\AssessmentDimension;
use App\Domain\Suitability\Enum\QuestionKey;
use App\Domain\User\Entity\Client;
use App\Domain\User\Entity\User;
use App\Domain\Workspace\Entity\Workspace;
use App\Tests\Application\ReflectionHelperTrait;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class InvestorProfileComparisonAssemblerTest extends TestCase
{
    use ReflectionHelperTrait;

    private InvestorProfileComparisonAssembler $assembler;
    private Workspace $workspace;
    private Client $client;
    private User $cgp;

    protected function setUp(): void
    {
        $this->assembler = new InvestorProfileComparisonAssembler(new InvestorProfileSynthesisAssembler());
        $this->workspace = $this->createEntityState(Workspace::class, ['slugId' => 'wrk_1', 'name' => 'Cabinet A']);
        $this->client = $this->createEntityState(Client::class, ['id' => Uuid::v7(), 'slugId' => 'cli_1', 'email' => 'a@b.fr', 'firstName' => 'A', 'lastName' => 'B']);
        $this->cgp = $this->createEntityState(User::class, ['id' => Uuid::v7(), 'firstName' => 'Marie', 'lastName' => 'Curie']);
    }

    /**
     * @param array<string, mixed> $answers
     */
    private function profile(array $answers, int $finalProfile, int $version): ValidatedInvestorProfile
    {
        $assessment = InvestorProfileAssessment::create($this->workspace, $this->client);

        return ValidatedInvestorProfile::validate(
            $this->workspace,
            $this->client,
            $assessment,
            $this->cgp,
            ['answers' => $answers, 'scoreSnapshot' => ['finalProfile' => $finalProfile]],
            version: $version,
        );
    }

    public function testFlagsOnlyTheAnswersThatActuallyChanged(): void
    {
        $older = $this->profile([
            QuestionKey::EXPERIENCE_YEARS->value => 2,
            QuestionKey::EXPERIENCE_HAS_EXPERIENCED_LOSSES->value => false,
        ], finalProfile: 3, version: 1);

        $newer = $this->profile([
            QuestionKey::EXPERIENCE_YEARS->value => 5,
            QuestionKey::EXPERIENCE_HAS_EXPERIENCED_LOSSES->value => false,
        ], finalProfile: 3, version: 2);

        $comparison = $this->assembler->assemble($older, $newer);

        self::assertSame(1, $comparison->oldVersion);
        self::assertSame(2, $comparison->newVersion);
        self::assertFalse($comparison->levelChanged);

        $experienceDimension = current(array_filter($comparison->dimensions, static fn (\App\Application\Suitability\DTO\Response\InvestorProfileComparisonDimensionResponse $d): bool => AssessmentDimension::EXPERIENCE->getLabel() === $d->label));
        self::assertNotFalse($experienceDimension);

        $answersByLabel = [];
        foreach ($experienceDimension->answers as $answer) {
            $answersByLabel[$answer->questionLabel] = $answer;
        }

        $yearsAnswer = $answersByLabel[QuestionKey::EXPERIENCE_YEARS->getLabel()];
        self::assertTrue($yearsAnswer->changed);
        self::assertSame('2', $yearsAnswer->oldAnswerLabel);
        self::assertSame('5', $yearsAnswer->newAnswerLabel);

        $lossesAnswer = $answersByLabel[QuestionKey::EXPERIENCE_HAS_EXPERIENCED_LOSSES->getLabel()];
        self::assertFalse($lossesAnswer->changed);
        self::assertSame('Non', $lossesAnswer->oldAnswerLabel);
        self::assertSame('Non', $lossesAnswer->newAnswerLabel);
    }

    public function testFlagsARetainedProfileLevelChange(): void
    {
        $older = $this->profile([], finalProfile: 2, version: 1);
        $newer = $this->profile([], finalProfile: 5, version: 2);

        $comparison = $this->assembler->assemble($older, $newer);

        self::assertTrue($comparison->levelChanged);
        self::assertSame(2, $comparison->oldRetainedLevel);
        self::assertSame(5, $comparison->newRetainedLevel);
        self::assertSame('Prudent', $comparison->oldRetainedLabel);
        self::assertSame('Dynamique', $comparison->newRetainedLabel);
    }

    public function testTreatsAQuestionAnsweredOnlyOnOneSideAsChanged(): void
    {
        $older = $this->profile([], finalProfile: 3, version: 1);
        $newer = $this->profile([QuestionKey::EXPERIENCE_YEARS->value => 3], finalProfile: 3, version: 2);

        $comparison = $this->assembler->assemble($older, $newer);

        $experienceDimension = current(array_filter($comparison->dimensions, static fn (\App\Application\Suitability\DTO\Response\InvestorProfileComparisonDimensionResponse $d): bool => AssessmentDimension::EXPERIENCE->getLabel() === $d->label));
        self::assertNotFalse($experienceDimension);

        $yearsAnswer = current(array_filter($experienceDimension->answers, static fn (\App\Application\Suitability\DTO\Response\InvestorProfileComparisonAnswerResponse $a): bool => $a->questionLabel === QuestionKey::EXPERIENCE_YEARS->getLabel()));
        self::assertNotFalse($yearsAnswer);
        self::assertTrue($yearsAnswer->changed);
        self::assertSame('Non renseigné', $yearsAnswer->oldAnswerLabel);
        self::assertSame('3', $yearsAnswer->newAnswerLabel);
    }

    public function testOmitsQuestionsNeverAnsweredOnEitherSide(): void
    {
        $older = $this->profile([], finalProfile: 1, version: 1);
        $newer = $this->profile([], finalProfile: 1, version: 2);

        $comparison = $this->assembler->assemble($older, $newer);

        foreach ($comparison->dimensions as $dimension) {
            self::assertSame([], $dimension->answers);
        }
    }
}
