<?php

declare(strict_types=1);

namespace App\Tests\Application\Suitability;

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

final class InvestorProfileSynthesisAssemblerTest extends TestCase
{
    use ReflectionHelperTrait;

    private InvestorProfileSynthesisAssembler $assembler;

    protected function setUp(): void
    {
        $this->assembler = new InvestorProfileSynthesisAssembler();
    }

    public function testAssemblesReadableLabelsForEachAnswerType(): void
    {
        $workspace = $this->createEntityState(Workspace::class, [
            'slugId' => 'wrk_1',
            'name' => 'Cabinet A',
            'legalName' => 'Cabinet A SAS',
            'address' => '1 rue de la Paix',
            'siret' => '12345678900011',
        ]);
        $client = $this->createEntityState(Client::class, [
            'id' => Uuid::v7(),
            'slugId' => 'cli_1',
            'email' => 'jean@example.com',
            'firstName' => 'Jean',
            'lastName' => 'Dupont',
        ]);
        $cgp = $this->createEntityState(User::class, ['id' => Uuid::v7(), 'firstName' => 'Marie', 'lastName' => 'Curie']);
        $assessment = InvestorProfileAssessment::create($workspace, $client);

        $profile = ValidatedInvestorProfile::validate(
            $workspace,
            $client,
            $assessment,
            $cgp,
            [
                'answers' => [
                    QuestionKey::KNOWLEDGE_OPCVM_ETF->value => 3,
                    QuestionKey::EXPERIENCE_PRODUCTS_HELD->value => ['opcvm_etf', 'assurance_vie'],
                    QuestionKey::EXPERIENCE_YEARS->value => 5,
                    QuestionKey::CAPACITY_ANNUAL_INCOME->value => 45000.0,
                    QuestionKey::EXPERIENCE_HAS_EXPERIENCED_LOSSES->value => true,
                ],
                'scoreSnapshot' => ['finalProfile' => 4],
            ],
            version: 1,
        );

        $synthesis = $this->assembler->assemble($profile);

        self::assertSame('Jean Dupont', $synthesis->clientFullName);
        self::assertSame('Cabinet A SAS', $synthesis->workspaceLegalName);
        self::assertSame(4, $synthesis->retainedProfileLevel);
        self::assertSame('Équilibré', $synthesis->retainedProfileLabel);
        self::assertFalse($synthesis->isOverridden);

        $knowledgeDimension = current(array_filter($synthesis->dimensions, static fn (\App\Application\Suitability\DTO\Response\InvestorProfileSynthesisDimensionResponse $d): bool => 'Connaissances financières' === $d->label));
        self::assertNotFalse($knowledgeDimension);
        $opcvmAnswer = current(array_filter($knowledgeDimension->answers, static fn (\App\Application\Suitability\DTO\Response\InvestorProfileSynthesisAnswerResponse $a): bool => str_contains($a->questionLabel, 'OPCVM')));
        self::assertNotFalse($opcvmAnswer);
        self::assertSame('Bonne connaissance', $opcvmAnswer->answerLabel);

        $experienceDimension = current(array_filter($synthesis->dimensions, static fn (\App\Application\Suitability\DTO\Response\InvestorProfileSynthesisDimensionResponse $d): bool => AssessmentDimension::EXPERIENCE->getLabel() === $d->label));
        self::assertNotFalse($experienceDimension);

        $labelsByAnswer = [];
        foreach ($experienceDimension->answers as $answer) {
            $labelsByAnswer[$answer->questionLabel] = $answer->answerLabel;
        }

        self::assertSame('OPCVM / ETF, Assurance-vie', $labelsByAnswer[QuestionKey::EXPERIENCE_PRODUCTS_HELD->getLabel()]);
        self::assertSame('5', $labelsByAnswer[QuestionKey::EXPERIENCE_YEARS->getLabel()]);
        self::assertSame('Oui', $labelsByAnswer[QuestionKey::EXPERIENCE_HAS_EXPERIENCED_LOSSES->getLabel()]);

        $capacityDimension = current(array_filter($synthesis->dimensions, static fn (\App\Application\Suitability\DTO\Response\InvestorProfileSynthesisDimensionResponse $d): bool => AssessmentDimension::CAPACITY->getLabel() === $d->label));
        self::assertNotFalse($capacityDimension);
        $incomeAnswer = current(array_filter($capacityDimension->answers, static fn (\App\Application\Suitability\DTO\Response\InvestorProfileSynthesisAnswerResponse $a): bool => $a->questionLabel === QuestionKey::CAPACITY_ANNUAL_INCOME->getLabel()));
        self::assertNotFalse($incomeAnswer);
        self::assertSame('45 000 €', $incomeAnswer->answerLabel);
    }

    public function testOmitsQuestionsThatWereNeverAnswered(): void
    {
        $workspace = $this->createEntityState(Workspace::class, ['slugId' => 'wrk_1', 'name' => 'Cabinet A']);
        $client = $this->createEntityState(Client::class, ['id' => Uuid::v7(), 'slugId' => 'cli_1', 'email' => 'a@b.fr', 'firstName' => 'A', 'lastName' => 'B']);
        $cgp = $this->createEntityState(User::class, ['id' => Uuid::v7(), 'firstName' => 'Marie', 'lastName' => 'Curie']);
        $assessment = InvestorProfileAssessment::create($workspace, $client);

        $profile = ValidatedInvestorProfile::validate(
            $workspace,
            $client,
            $assessment,
            $cgp,
            ['answers' => [], 'scoreSnapshot' => ['finalProfile' => 1]],
            version: 1,
        );

        $synthesis = $this->assembler->assemble($profile);

        foreach ($synthesis->dimensions as $dimension) {
            self::assertSame([], $dimension->answers);
        }
    }

    public function testSurfacesTheOverrideReasonWhenTheCgpCorrectedTheProfile(): void
    {
        $workspace = $this->createEntityState(Workspace::class, ['slugId' => 'wrk_1', 'name' => 'Cabinet A']);
        $client = $this->createEntityState(Client::class, ['id' => Uuid::v7(), 'slugId' => 'cli_1', 'email' => 'a@b.fr', 'firstName' => 'A', 'lastName' => 'B']);
        $cgp = $this->createEntityState(User::class, ['id' => Uuid::v7(), 'firstName' => 'Marie', 'lastName' => 'Curie']);
        $assessment = InvestorProfileAssessment::create($workspace, $client);

        $profile = ValidatedInvestorProfile::validate(
            $workspace,
            $client,
            $assessment,
            $cgp,
            ['answers' => [], 'scoreSnapshot' => ['finalProfile' => 5]],
            version: 1,
            overriddenProfileLevel: 3,
            overrideReason: 'Capacité à subir des pertes sous-déclarée.',
        );

        $synthesis = $this->assembler->assemble($profile);

        self::assertTrue($synthesis->isOverridden);
        self::assertSame(3, $synthesis->retainedProfileLevel);
        self::assertSame(5, $synthesis->computedProfileLevel);
        self::assertSame('Capacité à subir des pertes sous-déclarée.', $synthesis->overrideReason);
    }
}
