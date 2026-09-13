<?php

declare(strict_types=1);

namespace App\Infrastructure\Scheduler\Persistence;

use App\Domain\Scheduler\Entity\CronExecutionLog;
use App\Domain\Scheduler\Enum\CronJob;
use App\Domain\Scheduler\Repository\CronExecutionLogRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;

/**
 * @method CronExecutionLog|null find($id, $lockMode = null, $lockVersion = null)
 * @method CronExecutionLog|null findOneBy(array<string, mixed> $criteria, array<string, string>|null $orderBy = null)
 * @method CronExecutionLog[]    findAll()
 * @method CronExecutionLog[]    findBy(array<string, mixed> $criteria, array<string, string>|null $orderBy = null, $limit = null, $offset = null)
 */
final readonly class DoctrineCronExecutionLogRepository implements CronExecutionLogRepositoryInterface
{
    /** @var EntityRepository<CronExecutionLog> */
    private EntityRepository $repository;

    public function __construct(private EntityManagerInterface $entityManager)
    {
        $this->repository = $entityManager->getRepository(CronExecutionLog::class);
    }

    public function save(CronExecutionLog $log): void
    {
        $this->entityManager->persist($log);
        $this->entityManager->flush();
    }

    public function findLatestByJob(CronJob $job): ?CronExecutionLog
    {
        return $this->repository->createQueryBuilder('l')
            ->where('l.job = :job')
            ->setParameter('job', $job)
            ->orderBy('l.startedAt', 'DESC')
            // Départage à la seconde près (colonne TIMESTAMP(0)) : l'UUIDv7 encode
            // l'horodatage de création et reste trié dans le même ordre.
            ->addOrderBy('l.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return list<CronExecutionLog>
     */
    public function findRecentByJob(CronJob $job, int $limit = 10): array
    {
        return $this->repository->createQueryBuilder('l')
            ->where('l.job = :job')
            ->setParameter('job', $job)
            ->orderBy('l.startedAt', 'DESC')
            ->addOrderBy('l.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
