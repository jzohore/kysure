<?php

declare(strict_types=1);

namespace App\Infrastructure\Support\Command;

use App\Application\Support\UseCase\AutoResolveInactiveThreadsUseCase;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:support:auto-resolve',
    description: 'Clôture automatiquement les tickets de support inactifs depuis plus de 2 heures.',
)]
final class AutoResolveSupportThreadsCommand extends Command
{
    public function __construct(
        private readonly AutoResolveInactiveThreadsUseCase $autoResolveUseCase,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Exécution de la tâche : Clôture automatique des tickets inactifs');

        try {
            $count = $this->autoResolveUseCase->execute(
                new \DateInterval('PT2H'),  // Avertissement après 2 heures d'inactivité côté client
                new \DateInterval('PT30M'), // Clôture 30 minutes après l'avertissement si toujours sans réponse
            );

            if ($count['resolved'] > 0 || $count['warned'] > 0) {
                $io->success(sprintf('%d ticket(s) ont été clôturés avec succès.', $count['resolved']));
            } else {
                $io->info('Aucun ticket inactif nécessitant une clôture n\'a été trouvé.');
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error('Une erreur est survenue lors du traitement : ' . $e->getMessage());

            return Command::FAILURE;
        }
    }
}
