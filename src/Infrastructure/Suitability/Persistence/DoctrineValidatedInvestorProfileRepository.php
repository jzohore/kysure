<?php

declare(strict_types=1);

namespace App\Infrastructure\Suitability\Persistence;

use App\Domain\Suitability\Entity\InvestorProfileAssessment;
use App\Domain\Suitability\Entity\ValidatedInvestorProfile;
use App\Domain\Suitability\Enum\AssessmentStatus;
use App\Domain\Suitability\Repository\ValidatedInvestorProfileRepositoryInterface;
use App\Domain\User\Entity\Client;
use App\Domain\Workspace\Entity\Workspace;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Symfony\Component\Uid\Uuid;

/**
 * @method ValidatedInvestorProfile|null find($id, $lockMode = null, $lockVersion = null)
 * @method ValidatedInvestorProfile|null findOneBy(array<string, mixed> $criteria, array<string, string>|null $orderBy = null)
 * @method ValidatedInvestorProfile[]    findAll()
 * @method ValidatedInvestorProfile[]    findBy(array<string, mixed> $criteria, array<string, string>|null $orderBy = null, $limit = null, $offset = null)
 */
readonly class DoctrineValidatedInvestorProfileRepository implements ValidatedInvestorProfileRepositoryInterface
{
    /** @var EntityRepository<ValidatedInvestorProfile> */
    private EntityRepository $repository;

    public function __construct(private EntityManagerInterface $entityManager)
    {
        $this->repository = $entityManager->getRepository(ValidatedInvestorProfile::class);
    }

    public function save(ValidatedInvestorProfile $profile, bool $flush = true): void
    {
        $this->entityManager->persist($profile);

        if ($flush) {
            $this->entityManager->flush();
        }
    }

    public function findById(Uuid|string $id): ?ValidatedInvestorProfile
    {
        return $this->repository->find($id);
    }

    public function findBySlugId(string $slugId): ?ValidatedInvestorProfile
    {
        return $this->repository->findOneBy(['slugId' => $slugId]);
    }

    public function findBySlugIdAndWorkspace(string $slugId, Workspace $workspace): ?ValidatedInvestorProfile
    {
        return $this->repository->findOneBy(['slugId' => $slugId, 'workspace' => $workspace]);
    }

    public function findInForceByClient(Client $client, Workspace $workspace): ?ValidatedInvestorProfile
    {
        return $this->repository->createQueryBuilder('p')
            ->andWhere('p.client = :client')
            ->andWhere('p.workspace = :workspace')
            ->andWhere('p.revokedAt IS NULL')
            ->orderBy('p.version', 'DESC')
            ->setParameter('client', $client)
            ->setParameter('workspace', $workspace)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findLatestVersionNumber(Client $client, Workspace $workspace): int
    {
        $max = $this->repository->createQueryBuilder('p')
            ->select('MAX(p.version)')
            ->andWhere('p.client = :client')
            ->andWhere('p.workspace = :workspace')
            ->setParameter('client', $client)
            ->setParameter('workspace', $workspace)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $max;
    }

    public function findAllByClient(Client $client, Workspace $workspace): array
    {
        return $this->repository->createQueryBuilder('p')
            ->andWhere('p.client = :client')
            ->andWhere('p.workspace = :workspace')
            ->orderBy('p.version', 'DESC')
            ->setParameter('client', $client)
            ->setParameter('workspace', $workspace)
            ->getQuery()
            ->getResult();
    }

    public function countPendingValidationForWorkspace(Workspace $workspace): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(a.id)')
            ->from(InvestorProfileAssessment::class, 'a')
            ->andWhere('a.workspace = :workspace')
            ->andWhere('a.status = :status')
            ->andWhere('NOT EXISTS (
                SELECT 1 FROM ' . ValidatedInvestorProfile::class . ' p
                WHERE p.assessment = a AND p.revokedAt IS NULL
            )')
            ->setParameter('workspace', $workspace)
            ->setParameter('status', AssessmentStatus::SUBMITTED)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findPendingValidationForWorkspace(Workspace $workspace): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('a')
            ->from(InvestorProfileAssessment::class, 'a')
            ->andWhere('a.workspace = :workspace')
            ->andWhere('a.status = :status')
            ->andWhere('NOT EXISTS (
                SELECT 1 FROM ' . ValidatedInvestorProfile::class . ' p
                WHERE p.assessment = a AND p.revokedAt IS NULL
            )')
            ->orderBy('a.submittedAt', 'ASC')
            ->setParameter('workspace', $workspace)
            ->setParameter('status', AssessmentStatus::SUBMITTED)
            ->getQuery()
            ->getResult();
    }
}
