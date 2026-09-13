<?php

declare(strict_types=1);

namespace App\Domain\Scheduler\Entity;

use App\Domain\Scheduler\Enum\CronExecutionStatus;
use App\Domain\Scheduler\Enum\CronJob;
use App\Domain\Scheduler\Enum\CronTriggerSource;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;

use function Symfony\Component\Clock\now;

use Symfony\Component\Uid\Uuid;

/**
 * Historique d'exécution d'une tâche planifiée. Une ligne par tentative
 * (planification automatique ou déclenchement manuel depuis le back-office).
 */
#[ORM\Entity]
#[ORM\Table(name: 'cron_execution_logs')]
#[ORM\Index(columns: ['job'])]
class CronExecutionLog
{
    private const int ERROR_MESSAGE_MAX_LENGTH = 2000;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    public private(set) ?Uuid $id = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    public private(set) \DateTimeImmutable $startedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public private(set) ?\DateTimeImmutable $finishedAt = null;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: CronExecutionStatus::class)]
    public private(set) CronExecutionStatus $status;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public private(set) ?string $errorMessage = null;

    protected function __construct(
        #[ORM\Column(type: Types::STRING, length: 100, enumType: CronJob::class)]
        public private(set) CronJob $job,
        #[ORM\Column(type: Types::STRING, length: 20, enumType: CronTriggerSource::class)]
        public private(set) CronTriggerSource $triggeredBy,
    ) {
        $this->startedAt = now();
        $this->status = CronExecutionStatus::RUNNING;
    }

    public static function start(CronJob $job, CronTriggerSource $triggeredBy): self
    {
        return new self($job, $triggeredBy);
    }

    public function markSuccess(): void
    {
        $this->status = CronExecutionStatus::SUCCESS;
        $this->finishedAt = now();
    }

    public function markFailure(string $errorMessage): void
    {
        $this->status = CronExecutionStatus::FAILURE;
        $this->errorMessage = mb_substr($errorMessage, 0, self::ERROR_MESSAGE_MAX_LENGTH);
        $this->finishedAt = now();
    }

    public function markSkipped(string $reason): void
    {
        $this->status = CronExecutionStatus::SKIPPED;
        $this->errorMessage = $reason;
        $this->finishedAt = now();
    }
}
