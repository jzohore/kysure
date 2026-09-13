<?php

declare(strict_types=1);

namespace App\Infrastructure\Scheduler\Persistence;

use App\Domain\Scheduler\Entity\CronDefinition;
use App\Domain\Scheduler\Enum\CronJob;
use App\Domain\Scheduler\Repository\CronDefinitionRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;

/**
 * @method CronDefinition|null find($id, $lockMode = null, $lockVersion = null)
 * @method CronDefinition|null findOneBy(array<string, mixed> $criteria, array<string, string>|null $orderBy = null)
 * @method CronDefinition[]    findAll()
 * @method CronDefinition[]    findBy(array<string, mixed> $criteria, array<string, string>|null $orderBy = null, $limit = null, $offset = null)
 */
final readonly class DoctrineCronDefinitionRepository implements CronDefinitionRepositoryInterface
{
    /** @var EntityRepository<CronDefinition> */
    private EntityRepository $repository;

    public function __construct(private EntityManagerInterface $entityManager)
    {
        $this->repository = $entityManager->getRepository(CronDefinition::class);
    }

    public function save(CronDefinition $definition): void
    {
        $this->entityManager->persist($definition);
        $this->entityManager->flush();
    }

    public function findByJob(CronJob $job): ?CronDefinition
    {
        return $this->repository->findOneBy(['job' => $job]);
    }

    /**
     * @return list<CronDefinition>
     */
    public function findAll(): array
    {
        return $this->repository->findAll();
    }
}
