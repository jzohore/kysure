<?php

declare(strict_types=1);

namespace App\Tests\Application\Suitability;

use App\Application\Suitability\UseCase\SendAssessmentReminderUseCase;
use App\Domain\Suitability\Entity\InvestorProfileAssessment;
use App\Domain\Suitability\Enum\AnswerSource;
use App\Domain\Suitability\Enum\QuestionKey;
use App\Domain\Suitability\Repository\InvestorProfileAssessmentRepositoryInterface;
use App\Domain\User\Entity\Client;
use App\Domain\Workspace\Entity\Workspace;
use App\Infrastructure\Suitability\Message\SendAssessmentReminderMessage;
use App\Tests\Application\ReflectionHelperTrait;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

final class SendAssessmentReminderUseCaseTest extends TestCase
{
    use ReflectionHelperTrait;

    private Workspace $workspace;
    private Client $client;

    protected function setUp(): void
    {
        $this->workspace = $this->createEntityState(Workspace::class, ['slugId' => 'wrk_1', 'name' => 'Cabinet']);
        $this->client = $this->createEntityState(Client::class, ['id' => Uuid::v7(), 'slugId' => 'cli_1', 'email' => 'jean@example.com']);
    }

    public function testOnlyRemindsAssessmentsNearCompletionAndMarksThemAsReminded(): void
    {
        $nearCompletion = $this->assessmentAnsweredAtRatio(0.8);
        $justStarted = $this->assessmentAnsweredAtRatio(0.1);

        $assessmentRepo = $this->createMock(InvestorProfileAssessmentRepositoryInterface::class);
        $assessmentRepo->method('findStalledDraftsNeedingReminder')->willReturn([$nearCompletion, $justStarted]);
        $assessmentRepo->expects(self::once())->method('save')->with($nearCompletion);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())->method('dispatch')->with(
            self::callback(static function (SendAssessmentReminderMessage $message) use ($nearCompletion): bool {
                self::assertSame($nearCompletion->slugId, $message->assessmentSlugId);

                return true;
            }),
        )->willReturn(new Envelope(new SendAssessmentReminderMessage('ipa_dummy')));

        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new \DateTimeImmutable());

        $useCase = new SendAssessmentReminderUseCase($assessmentRepo, $bus, $clock);
        ($useCase)();

        self::assertTrue($nearCompletion->hasReminderBeenSent());
        self::assertFalse($justStarted->hasReminderBeenSent());
    }

    public function testDoesNothingWhenNoStalledDraftFound(): void
    {
        $assessmentRepo = $this->createMock(InvestorProfileAssessmentRepositoryInterface::class);
        $assessmentRepo->method('findStalledDraftsNeedingReminder')->willReturn([]);
        $assessmentRepo->expects(self::never())->method('save');

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new \DateTimeImmutable());

        $useCase = new SendAssessmentReminderUseCase($assessmentRepo, $bus, $clock);
        ($useCase)();
    }

    private function assessmentAnsweredAtRatio(float $ratio): InvestorProfileAssessment
    {
        $assessment = InvestorProfileAssessment::create($this->workspace, $this->client);
        $questions = QuestionKey::cases();
        $countToAnswer = (int) round(\count($questions) * $ratio);

        for ($i = 0; $i < $countToAnswer; ++$i) {
            $assessment->recordAnswer($questions[$i], 'reponse', AnswerSource::CLIENT, $this->client->id);
        }

        return $assessment;
    }
}
