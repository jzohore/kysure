<?php

declare(strict_types=1);

namespace App\Infrastructure\ProductCatalogue\Persistence;

use App\Domain\ProductCatalogue\Entity\FinancialProduct;
use App\Domain\ProductCatalogue\Repository\FinancialProductRepositoryInterface;
use App\Domain\Workspace\Entity\Workspace;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;

/**
 * @method FinancialProduct|null find($id, $lockMode = null, $lockVersion = null)
 * @method FinancialProduct|null findOneBy(array<string, mixed> $criteria, array<string, string>|null $orderBy = null)
 * @method FinancialProduct[]    findAll()
 * @method FinancialProduct[]    findBy(array<string, mixed> $criteria, array<string, string>|null $orderBy = null, $limit = null, $offset = null)
 */
readonly class FinancialProductRepository implements FinancialProductRepositoryInterface
{
    /** @var EntityRepository<FinancialProduct> */
    private EntityRepository $repository;

    public function __construct(private EntityManagerInterface $entityManager)
    {
        $this->repository = $entityManager->getRepository(FinancialProduct::class);
    }

    public function findBySlugIdAndWorkspace(string $slugId, Workspace $workspace): ?FinancialProduct
    {
        return $this->repository->findOneBy(['slugId' => $slugId, 'workspace' => $workspace]);
    }

    public function findByWorkspace(Workspace $workspace): array
    {
        return $this->repository->findBy(['workspace' => $workspace], ['name' => 'ASC']);
    }

    public function save(FinancialProduct $financialProduct): void
    {
        $this->entityManager->persist($financialProduct);
        $this->entityManager->flush();
    }
}
