<?php

declare(strict_types=1);

namespace App\Infrastructure\Support\Command;

use App\Application\Support\UseCase\AlertOverdueSupportThreadsUseCase;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:support:alert-sla-breach',
    description: 'Alerte Slack l\'équipe KYSURE pour chaque ticket support ayant dépassé son échéance SLA.',
)]
final class AlertOverdueSupportThreadsCommand extends Command
{
    public function __construct(
        private readonly AlertOverdueSupportThreadsUseCase $alertOverdueUseCase,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $count = $this->alertOverdueUseCase->execute();

        if ($count > 0) {
            $io->success(sprintf('%d ticket(s) en dépassement SLA signalé(s) sur Slack.', $count));
        } else {
            $io->info('Aucun ticket en dépassement SLA nécessitant une alerte.');
        }

        return Command::SUCCESS;
    }
}
