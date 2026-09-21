<?php

declare(strict_types=1);

namespace App\Infrastructure\Suitability\Command;

use App\Application\Suitability\UseCase\SendAssessmentReminderUseCase;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;

#[AsCommand(
    name: 'app:suitability:send-reminder-mail',
    description: 'Relance par email les clients dont le questionnaire profil investisseur est en pause depuis plus d\'un jour, proche de la fin',
)]
readonly class SendInvestorProfileReminderCommand
{
    public function __construct(
        private SendAssessmentReminderUseCase $sendAssessmentReminderUseCase,
    ) {
    }

    public function __invoke(): int
    {
        ($this->sendAssessmentReminderUseCase)();

        return Command::SUCCESS;
    }
}
