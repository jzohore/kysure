<?php

declare(strict_types=1);

namespace App\Infrastructure\Scheduler\Handler;

use App\Application\Scheduler\UseCase\RunCronJobUseCase;
use App\Infrastructure\Scheduler\Message\RunCronJobMessage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class RunCronJobMessageHandler
{
    public function __construct(private RunCronJobUseCase $runCronJobUseCase)
    {
    }

    public function __invoke(RunCronJobMessage $message): void
    {
        ($this->runCronJobUseCase)($message->job, $message->triggeredBy);
    }
}
