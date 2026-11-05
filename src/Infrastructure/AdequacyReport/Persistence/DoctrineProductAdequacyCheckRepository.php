<?php

declare(strict_types=1);

namespace App\Infrastructure\AdequacyReport\Persistence;

use App\Domain\AdequacyReport\Entity\ProductAdequacyCheck;
use App\Domain\AdequacyReport\Repository\ProductAdequacyCheckRepositoryInterface;
use App\Domain\Compliance\Entity\ComplianceFolder;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;

readonly class DoctrineProductAdequacyCheckRepository implements ProductAdequacyCheckRepositoryInterface
{
    /** @var EntityRepository<ProductAdequacyCheck> */
    private EntityRepository $repository;

    public function __construct(private EntityManagerInterface $entityManager)
    {
        $this->repository = $entityManager->getRepository(ProductAdequacyCheck::class);
    }

    public function save(ProductAdequacyCheck $check, bool $flush = true): void
    {
        $this->entityManager->persist($check);

        if ($flush) {
            $this->entityManager->flush();
        }
    }

    public function findBySlugId(string $slugId): ?ProductAdequacyCheck
    {
        return $this->repository->findOneBy(['slugId' => $slugId]);
    }

    public function findByFolder(ComplianceFolder $folder, int $limit = 20): array
    {
        /** @var list<ProductAdequacyCheck> $checks */
        $checks = $this->repository->findBy(
            ['complianceFolder' => $folder],
            ['checkedAt' => 'DESC'],
            $limit,
        );

        return $checks;
    }
}
