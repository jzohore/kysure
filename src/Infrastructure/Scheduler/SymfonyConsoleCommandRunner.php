<?php

declare(strict_types=1);

namespace App\Infrastructure\Scheduler;

use App\Domain\Scheduler\Enum\CronJob;
use App\Domain\Scheduler\Gateway\ConsoleCommandRunnerInterface;
use App\Domain\Scheduler\ValueObject\CommandRunResult;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Exécute une commande console applicative en mémoire, dans le même process
 * que le worker (pas de fork). Le code de sortie et la sortie combinée
 * (stdout + stderr) sont capturés pour alimenter l'historique des tâches.
 */
final readonly class SymfonyConsoleCommandRunner implements ConsoleCommandRunnerInterface
{
    public function __construct(private KernelInterface $kernel)
    {
    }

    public function run(CronJob $job): CommandRunResult
    {
        $application = new Application($this->kernel);
        $application->setAutoExit(false);
        $application->setCatchExceptions(false);

        $output = new BufferedOutput();

        try {
            $exitCode = $application->run(new StringInput(CronCatalog::commandLineFor($job)), $output);
        } catch (\Throwable $e) {
            return CommandRunResult::fromExitCode(1, $output->fetch() . $e->getMessage());
        }

        return CommandRunResult::fromExitCode($exitCode, $output->fetch());
    }
}
