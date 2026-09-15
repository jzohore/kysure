<?php

declare(strict_types=1);

namespace App\Infrastructure\User\Persistence;

use App\Domain\User\Entity\Admin;
use App\Domain\User\Enum\AdminRole;
use App\Domain\User\Repository\AdminRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Pagerfanta\Doctrine\ORM\QueryAdapter;
use Pagerfanta\Exception\OutOfRangeCurrentPageException;
use Pagerfanta\Pagerfanta;
use Symfony\Component\Uid\Uuid;

/**
 * @method Admin|null find($id, $lockMode = null, $lockVersion = null)
 * @method Admin|null findOneBy(array<string, mixed> $criteria, array<string, string>|null $orderBy = null)
 * @method Admin[]    findAll()
 * @method Admin[]    findBy(array<string, mixed> $criteria, array<string, string>|null $orderBy = null, $limit = null, $offset = null)
 */
readonly class AdminRepository implements AdminRepositoryInterface
{
    /** @var EntityRepository<Admin> */
    private EntityRepository $repository;

    public function __construct(private EntityManagerInterface $entityManager)
    {
        $this->repository = $entityManager->getRepository(Admin::class);
    }

    public function findByEmail(string $email): ?Admin
    {
        return $this->repository->findOneBy(['email' => $email]);
    }

    public function findById(Uuid|string $id): ?Admin
    {
        return $this->repository->find($id);
    }

    public function findBySlugId(string $slugId): ?Admin
    {
        return $this->repository->findOneBy(['slugId' => $slugId]);
    }

    public function save(Admin $client, bool $flush = true): void
    {
        $this->entityManager->persist($client);
        if ($flush) {
            $this->entityManager->flush();
        }
    }

    public function delete(Admin $admin): void
    {
        $this->entityManager->remove($admin);
        $this->entityManager->flush();
    }

    public function findByMagicLink(string $magicLink): ?Admin
    {
        return $this->repository->findOneBy(['magicLinkToken' => $magicLink]);
    }

    public function paginateForList(int $page, int $perPage, ?string $search, string $status): Pagerfanta
    {
        $qb = $this->repository->createQueryBuilder('a')
            ->orderBy('a.createdAt', 'DESC');

        $qb = match ($status) {
            'active' => $qb->andWhere('a.isActif = true AND a.archivedAt IS NULL'),
            'suspended' => $qb->andWhere('a.isActif = false AND a.suspendedAt IS NOT NULL AND a.archivedAt IS NULL'),
            'archived' => $qb->andWhere('a.archivedAt IS NOT NULL'),
            default => $qb,
        };

        if (!in_array($search, [null, '', '0'], true)) {
            $qb->andWhere(
                'LOWER(a.email) LIKE LOWER(:q) OR LOWER(a.firstName) LIKE LOWER(:q) OR LOWER(a.lastName) LIKE LOWER(:q)'
            )->setParameter('q', '%' . $search . '%');
        }

        $pager = new Pagerfanta(new QueryAdapter($qb));
        $pager->setMaxPerPage(max(1, $perPage));

        try {
            $pager->setCurrentPage(max(1, $page));
        } catch (OutOfRangeCurrentPageException) {
            $pager->setCurrentPage(1);
        }

        return $pager;
    }

    /**
     * @return Admin[]
     */
    public function findAllActive(): array
    {
        return $this->repository->createQueryBuilder('a')
            ->where('a.isActif = true')
            ->andWhere('a.archivedAt IS NULL')
            ->orderBy('a.firstName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function countActiveSuperAdmins(): int
    {
        $sql = <<<'SQL'
            SELECT COUNT(*) FROM "admins"
            WHERE is_actif = true
              AND archived_at IS NULL
              AND CAST(roles AS text) LIKE :needle
            SQL;

        $count = $this->entityManager->getConnection()->fetchOne($sql, [
            'needle' => '%"' . AdminRole::SUPER_ADMIN->value . '"%',
        ]);

        return (int) $count;
    }
}
