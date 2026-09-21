<?php

declare(strict_types=1);

namespace App\Tests\Application\Suitability;

use App\Application\Suitability\UseCase\RecordAssessmentAnswerUseCase;
use App\Domain\Suitability\Entity\InvestorProfileAssessment;
use App\Domain\Suitability\Enum\QuestionKey;
use App\Domain\Suitability\Repository\InvestorProfileAssessmentRepositoryInterface;
use App\Domain\User\Entity\Client;
use App\Domain\Workspace\Entity\Workspace;
use App\Tests\Application\ReflectionHelperTrait;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class RecordAssessmentAnswerUseCaseTest extends TestCase
{
    use ReflectionHelperTrait;

    public function testRecordsTheAnswerAndSavesImmediately(): void
    {
        $workspace = $this->createEntityState(Workspace::class, ['slugId' => 'wrk_1', 'name' => 'Cabinet']);
        $client = $this->createEntityState(Client::class, ['id' => Uuid::v7(), 'slugId' => 'cli_1', 'email' => 'jean@example.com']);
        $assessment = InvestorProfileAssessment::create($workspace, $client);

        $repo = $this->createMock(InvestorProfileAssessmentRepositoryInterface::class);
        $repo->expects(self::once())->method('save')->with($assessment);

        $useCase = new RecordAssessmentAnswerUseCase($repo);
        ($useCase)($assessment, QuestionKey::SUSTAINABILITY_PREFERENCE, 'sans_preference', $client);

        self::assertSame('sans_preference', $assessment->getAnswerValue(QuestionKey::SUSTAINABILITY_PREFERENCE));
    }
}
