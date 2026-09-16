<?php

declare(strict_types=1);

namespace App\Infrastructure\Suitability\Persistence;

use App\Domain\Suitability\Entity\InvestorProfileAssessment;
use App\Domain\Suitability\Enum\AssessmentStatus;
use App\Domain\Suitability\Repository\InvestorProfileAssessmentRepositoryInterface;
use App\Domain\User\Entity\Client;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;

/**
 * @method InvestorProfileAssessment|null find($id, $lockMode = null, $lockVersion = null)
 * @method InvestorProfileAssessment|null findOneBy(array<string, mixed> $criteria, array<string, string>|null $orderBy = null)
 * @method InvestorProfileAssessment[]    findAll()
 * @method InvestorProfileAssessment[]    findBy(array<string, mixed> $criteria, array<string, string>|null $orderBy = null, $limit = null, $offset = null)
 */
readonly class DoctrineInvestorProfileAssessmentRepository implements InvestorProfileAssessmentRepositoryInterface
{
    /** @var EntityRepository<InvestorProfileAssessment> */
    private EntityRepository $repository;

    public function __construct(private EntityManagerInterface $entityManager)
    {
        $this->repository = $entityManager->getRepository(InvestorProfileAssessment::class);
    }

    public function save(InvestorProfileAssessment $assessment, bool $flush = true): void
    {
        $this->entityManager->persist($assessment);

        if ($flush) {
            $this->entityManager->flush();
        }
    }

    public function findOneBySlugId(string $slugId): ?InvestorProfileAssessment
    {
        return $this->repository->findOneBy(['slugId' => $slugId]);
    }

    public function findActiveDraftForClient(Client $client): ?InvestorProfileAssessment
    {
        return $this->repository->createQueryBuilder('a')
            ->where('a.client = :client')
            ->andWhere('a.status = :status')
            ->setParameter('client', $client)
            ->setParameter('status', AssessmentStatus::DRAFT)
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findLatestSubmittedForClient(Client $client): ?InvestorProfileAssessment
    {
        return $this->repository->createQueryBuilder('a')
            ->where('a.client = :client')
            ->andWhere('a.status = :status')
            ->setParameter('client', $client)
            ->setParameter('status', AssessmentStatus::SUBMITTED)
            ->orderBy('a.submittedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
