<?php

declare(strict_types=1);

namespace App\Infrastructure\AdequacyReport\Persistence;

use App\Domain\AdequacyReport\Entity\AdequacyReport;
use App\Domain\AdequacyReport\Repository\AdequacyReportRepositoryInterface;
use App\Domain\Compliance\Entity\ComplianceFolder;
use App\Domain\Suitability\Entity\ValidatedInvestorProfile;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;

readonly class DoctrineAdequacyReportRepository implements AdequacyReportRepositoryInterface
{
    /** @var EntityRepository<AdequacyReport> */
    private EntityRepository $repository;

    public function __construct(private EntityManagerInterface $entityManager)
    {
        $this->repository = $entityManager->getRepository(AdequacyReport::class);
    }

    public function save(AdequacyReport $report, bool $flush = true): void
    {
        $this->entityManager->persist($report);

        if ($flush) {
            $this->entityManager->flush();
        }
    }

    public function findBySlugId(string $slugId): ?AdequacyReport
    {
        return $this->repository->findOneBy(['slugId' => $slugId]);
    }

    public function findByFolder(ComplianceFolder $folder): array
    {
        /** @var list<AdequacyReport> $reports */
        $reports = $this->repository->findBy(['complianceFolder' => $folder], ['version' => 'DESC']);

        return $reports;
    }

    public function findInForceByFolder(ComplianceFolder $folder): ?AdequacyReport
    {
        return $this->repository->findOneBy(['complianceFolder' => $folder, 'revokedAt' => null], ['version' => 'DESC']);
    }

    public function findLatestVersionNumber(ComplianceFolder $folder): int
    {
        $max = $this->repository->createQueryBuilder('r')
            ->select('MAX(r.version)')
            ->where('r.complianceFolder = :folder')
            ->setParameter('folder', $folder)
            ->getQuery()
            ->getSingleScalarResult();

        return is_numeric($max) ? (int) $max : 0;
    }

    public function findInForceByInvestorProfile(ValidatedInvestorProfile $profile): array
    {
        /** @var list<AdequacyReport> $reports */
        $reports = $this->repository->findBy(['investorProfile' => $profile, 'revokedAt' => null]);

        return $reports;
    }
}
