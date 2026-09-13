<?php

declare(strict_types=1);

namespace App\Domain\Scheduler\Entity;

use App\Domain\Scheduler\Enum\CronFrequency;
use App\Domain\Scheduler\Enum\CronJob;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;

use function Symfony\Component\Clock\now;

use Symfony\Component\Uid\Uuid;

/**
 * État d'activation d'une tâche planifiée, piloté depuis le back-office.
 * La fréquence et la commande exécutée restent définies dans le code
 * ({@see \App\Infrastructure\Scheduler\CronCatalog}) : cette entité ne fait
 * que suspendre ou réactiver le déclenchement automatique.
 */
#[ORM\Entity]
#[ORM\Table(name: 'cron_definitions')]
class CronDefinition
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    public private(set) ?Uuid $id = null;

    #[ORM\Column(type: Types::BOOLEAN)]
    public private(set) bool $enabled;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    public private(set) \DateTimeImmutable $updatedAt;

    protected function __construct(
        #[ORM\Column(type: Types::STRING, length: 100, unique: true, enumType: CronJob::class)]
        public private(set) CronJob $job,
        #[ORM\Column(type: Types::STRING, length: 30, enumType: CronFrequency::class)]
        public private(set) CronFrequency $frequency,
    ) {
        $this->enabled = true;
        $this->updatedAt = now();
    }

    public static function register(CronJob $job): self
    {
        return new self($job, $job->getDefaultFrequency());
    }

    public function enable(): void
    {
        $this->enabled = true;
        $this->updatedAt = now();
    }

    public function disable(): void
    {
        $this->enabled = false;
        $this->updatedAt = now();
    }

    public function changeFrequency(CronFrequency $frequency): void
    {
        $this->frequency = $frequency;
        $this->updatedAt = now();
    }
}
