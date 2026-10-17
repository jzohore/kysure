<?php

declare(strict_types=1);

namespace App\Infrastructure\Screening\Persistence;

use App\Domain\Screening\Entity\ScreeningAudit;
use App\Domain\Screening\Enum\ScreeningStatus;
use App\Domain\Screening\Exception\AuditNotFoundException;
use App\Domain\Screening\Repository\ScreeningAuditRepositoryInterface;
use App\Domain\Workspace\Entity\Workspace;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Pagerfanta\Doctrine\ORM\QueryAdapter;
use Pagerfanta\Pagerfanta;
use Symfony\Component\Uid\Uuid;

/**
 * @method ScreeningAudit|null find($id, $lockMode = null, $lockVersion = null)
 * @method ScreeningAudit|null findOneBy(array<string, mixed> $criteria, array<string, string>|null $orderBy = null)
 * @method ScreeningAudit[]    findAll()
 * @method ScreeningAudit[]    findBy(array<string, mixed> $criteria, array<string, string>|null $orderBy = null, $limit = null, $offset = null)
 */
readonly class DoctrineScreeningAuditRepository implements ScreeningAuditRepositoryInterface
{
    /** @var EntityRepository<ScreeningAudit> */
    private EntityRepository $repository;

    public function __construct(private EntityManagerInterface $entityManager)
    {
        $this->repository = $entityManager->getRepository(ScreeningAudit::class);
    }

    public function save(ScreeningAudit $audit): void
    {
        $this->entityManager->persist($audit);
        $this->entityManager->flush();
    }

    public function saveBatchAndDetach(array $audits): void
    {
        foreach ($audits as $audit) {
            $this->entityManager->persist($audit);
        }

        $this->entityManager->flush();

        foreach ($audits as $audit) {
            $this->entityManager->detach($audit);
        }
    }

    public function findRecentIdenticalSearch(Workspace $workspace, string $query, int $hoursLimit): ?ScreeningAudit
    {
        $limitDate = new \DateTimeImmutable()->modify("-{$hoursLimit} hours");

        return $this->repository->createQueryBuilder('s')
            ->where('s.workspace = :workspace')
            ->andWhere('s.query = :query')
            ->andWhere('s.createdAt >= :limitDate')
            ->setParameter('workspace', $workspace)
            ->setParameter('query', $query)
            ->setParameter('limitDate', $limitDate)
            ->orderBy('s.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOneBySlug(string $id): ?ScreeningAudit
    {
        return $this->repository->findOneBy(['slugId' => $id]);
    }

    public function getScreeningList(string $workspaceSlugId, ?string $search = null): Pagerfanta
    {
        $qb = $this->repository->createQueryBuilder('screeningAudit')
            ->select('screeningAudit', 'workspace')
            ->leftJoin('screeningAudit.workspace', 'workspace')
            ->andWhere('workspace.slugId = :workspaceSlugid')
            ->setParameter('workspaceSlugid', $workspaceSlugId)
            ->orderBy('screeningAudit.createdAt', 'DESC');

        if ($search) {
            $qb->andWhere('screeningAudit.query LIKE :search ')
                ->setParameter('search', '%' . $search . '%');
        }

        return new Pagerfanta(new QueryAdapter($qb));
    }

    /**
     * @return int
     *             Compte le nombre de recherches d'un Workspace depuis une date donnée
     */
    public function countSearchesSince(Workspace $workspace, \DateTimeImmutable $since): int
    {
        return (int) $this->repository->createQueryBuilder('sa')
            ->select('COUNT(sa.id)')
            ->where('sa.workspace = :workspace')
            ->andWhere('sa.createdAt >= :since')
            ->setParameter('workspace', $workspace)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countAll(): int
    {
        return (int) $this->repository->createQueryBuilder('sa')
            ->select('COUNT(sa.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return ScreeningAudit[]
     */
    public function findRecentByWorkspace(Workspace $workspace, int $limit = 5): array
    {
        return $this->repository->createQueryBuilder('sa')
            ->where('sa.workspace = :workspace')
            ->setParameter('workspace', $workspace)
            ->orderBy('sa.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countInProgressForWorkspace(Workspace $workspace): int
    {
        return (int) $this->repository->createQueryBuilder('sa')
            ->select('COUNT(sa.id)')
            ->where('sa.workspace = :workspace')
            ->andWhere('sa.status IN (:pending)')
            ->setParameter('workspace', $workspace)
            ->setParameter('pending', [ScreeningStatus::WAIT, ScreeningStatus::PENDING])
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findMinimizableResults(\DateTimeImmutable $createdBefore, int $limit): array
    {
        /** @var list<ScreeningAudit> $result */
        $result = $this->repository->createQueryBuilder('s')
            ->andWhere('s.createdAt < :before')
            ->andWhere('s.resultsMinimizedAt IS NULL')
            ->andWhere('s.totalMatches > 0')
            ->setParameter('before', $createdBefore)
            ->orderBy('s.createdAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $result;
    }

    public function getById(Uuid|string $id): ScreeningAudit
    {
        $audit = $this->repository->find($id);

        if (null === $audit) {
            throw AuditNotFoundException::withId((string) $id);
        }

        return $audit;
    }
}
