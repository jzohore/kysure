<?php

declare(strict_types=1);

namespace App\Infrastructure\Compliance\Persistence;

use App\Domain\Compliance\Entity\ComplianceFolder;
use App\Domain\Compliance\Enum\ComplianceFolderStatus;
use App\Domain\Compliance\Exception\ComplianceFolderNotFoundException;
use App\Domain\Compliance\Repository\ComplianceFolderRepositoryInterface;
use App\Domain\User\Entity\Client;
use App\Domain\Workspace\Entity\Workspace;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Pagerfanta\Doctrine\ORM\QueryAdapter;
use Pagerfanta\Exception\OutOfRangeCurrentPageException;
use Pagerfanta\Pagerfanta;
use Symfony\Component\Uid\Uuid;

/**
 * @method ComplianceFolder|null find($id, $lockMode = null, $lockVersion = null)
 * @method ComplianceFolder|null findOneBy(array<string, mixed> $criteria, array<string, string>|null $orderBy = null)
 * @method ComplianceFolder[]    findAll()
 * @method ComplianceFolder[]    findBy(array<string, mixed> $criteria, array<string, string>|null $orderBy = null, $limit = null, $offset = null)
 */
class ComplianceFolderRepository implements ComplianceFolderRepositoryInterface
{
    /** @var EntityRepository<ComplianceFolder> */
    private readonly EntityRepository $repository;

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
        $this->repository = $entityManager->getRepository(ComplianceFolder::class);
    }

    public function save(ComplianceFolder $folder, bool $flush = true): void
    {
        $this->entityManager->persist($folder);
        if ($flush) {
            $this->entityManager->flush();
        }
    }

    public function remove(ComplianceFolder $folder, bool $flush = true): void
    {
        $this->entityManager->remove($folder);
        if ($flush) {
            $this->entityManager->flush();
        }
    }

    public function findById(Uuid|string $id): ComplianceFolder
    {
        $folder = $this->repository->find($id);

        if (!$folder instanceof ComplianceFolder) {
            throw ComplianceFolderNotFoundException::withId((string) $id);
        }

        return $folder;
    }

    public function findByReference(string $reference): ComplianceFolder
    {
        $folder = $this->repository->findOneBy(['reference' => $reference]);

        if (!$folder instanceof ComplianceFolder) {
            throw ComplianceFolderNotFoundException::withReference($reference);
        }

        return $folder;
    }

    /**
     * @return array<ComplianceFolder>
     */
    public function findAllActiveByWorkspace(Workspace $workspace): array
    {
        return $this->repository->createQueryBuilder('cf')
            ->andWhere('cf.workspace = :workspace')
            ->andWhere('cf.status = :status')->setParameter('status', ComplianceFolderStatus::APPROVED)
            ->setParameter('workspace', $workspace)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Pagerfanta<ComplianceFolder>
     */
    public function findAllByWorkspace(Workspace $workspace, ?string $search = null, ?ComplianceFolderStatus $status = null): Pagerfanta
    {
        $qb = $this->repository->createQueryBuilder('cf')
            ->select('cf', 'workspace', 'owner')
            ->leftJoin('cf.workspace', 'workspace')
            ->leftJoin('cf.assignedReviewer', 'owner')
            ->andWhere('cf.workspace = :workspace')
            ->setParameter('workspace', $workspace)
            ->andWhere('cf.status != :deletedStatus')
            ->setParameter('deletedStatus', ComplianceFolderStatus::DELETED) // Adapte la valeur de l'Enum selon ton code
            ->orderBy('cf.createdAt', 'DESC');

        if ($search) {
            $qb->andWhere('cf.reference LIKE :search')
                ->setParameter('search', '%' . $search . '%');
        }

        // Filtre explicite si l'utilisateur sélectionne un statut précis dans l'UI
        if ($status instanceof ComplianceFolderStatus) {
            $qb->andWhere('cf.status = :status')
                ->setParameter('status', $status);
        }

        return new Pagerfanta(new QueryAdapter($qb));
    }

    public function findAllForAdmin(int $page, int $perPage, ?string $search = null, ?ComplianceFolderStatus $status = null): Pagerfanta
    {
        $qb = $this->repository->createQueryBuilder('cf')
            ->select('cf', 'w')
            ->leftJoin('cf.workspace', 'w')
            ->orderBy('cf.createdAt', 'DESC');

        if ($status instanceof ComplianceFolderStatus) {
            $qb->andWhere('cf.status = :status')->setParameter('status', $status);
        } else {
            $qb->andWhere('cf.status != :deleted')->setParameter('deleted', ComplianceFolderStatus::DELETED);
        }

        if (!in_array($search, [null, '', '0'], true)) {
            $qb->andWhere('LOWER(cf.reference) LIKE LOWER(:q) OR LOWER(w.name) LIKE LOWER(:q)')
                ->setParameter('q', '%' . $search . '%');
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

    public function countDraftsForWorkspace(Workspace $workspace): int
    {
        return (int) $this->repository->createQueryBuilder('cf')
            ->select('COUNT(cf.id)')
            ->leftJoin('cf.workspace', 'workspace')
            ->where('workspace = :workspace')
            ->andWhere('cf.status = :state')
            ->setParameter('workspace', $workspace)
            ->setParameter('state', ComplianceFolderStatus::DRAFT)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countActiveForWorkspace(Workspace $workspace): int
    {
        return (int) $this->repository->createQueryBuilder('cf')
            ->select('COUNT(cf.id)')
            ->where('cf.workspace = :workspace')
            ->andWhere('cf.status NOT IN (:excluded)')
            ->setParameter('workspace', $workspace)
            ->setParameter('excluded', [
                ComplianceFolderStatus::DRAFT,
                ComplianceFolderStatus::DELETED,
                ComplianceFolderStatus::ARCHIVED,
                ComplianceFolderStatus::REJECTED,
            ])
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findRecentByWorkspace(Workspace $workspace, int $limit = 5): array
    {
        return $this->repository->createQueryBuilder('cf')
            ->where('cf.workspace = :workspace')
            ->andWhere('cf.status != :deletedStatus')
            ->setParameter('workspace', $workspace)
            ->setParameter('deletedStatus', ComplianceFolderStatus::DELETED)
            ->orderBy('cf.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countByStatusesForWorkspace(Workspace $workspace, array $statuses): int
    {
        return (int) $this->repository->createQueryBuilder('cf')
            ->select('COUNT(cf.id)')
            ->where('cf.workspace = :workspace')
            ->andWhere('cf.status IN (:statuses)')
            ->setParameter('workspace', $workspace)
            ->setParameter('statuses', $statuses)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countForWorkspace(Workspace $workspace): int
    {
        return (int) $this->repository->createQueryBuilder('cf')
            ->select('COUNT(cf.id)')
            ->where('cf.workspace = :workspace')
            ->andWhere('cf.status != :deletedStatus')
            ->setParameter('workspace', $workspace)
            ->setParameter('deletedStatus', ComplianceFolderStatus::DELETED)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findOneLastDraftIndividuals(string $method, Workspace $workspace): ?ComplianceFolder
    {
        return $this->repository->createQueryBuilder('cf')
            ->select('cf')
            ->where('cf.status = :state')
            ->andWhere('cf.workspace = :workspace')
            ->andWhere('cf.creationMethod = :method')
            ->setParameter('state', ComplianceFolderStatus::DRAFT)
            ->setParameter('method', $method)
            ->setParameter('workspace', $workspace)
            ->setMaxResults(1)
            ->orderBy('cf.createdAt', 'DESC')
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return list<ComplianceFolder>
     */
    public function findAllActiveForClient(Client $client): array
    {
        // 🚨 RÈGLE MÉTIER KYSURE :
        // Le portail sert uniquement à la collecte KYC.
        // On ignore les dossiers en cours de génération ou de signature DocuSeal.
        // On récupère le dossier le plus récent ayant au moins atteint le statut DER_SIGNED.

        return $this->repository->createQueryBuilder('cf')
            ->where('cf.client = :client')
            ->andWhere('cf.status IN (:kycStatuses)')
            ->setParameter('client', $client)
            ->setParameter('kycStatuses', ComplianceFolderStatus::getKycPhaseStatuses())
            ->orderBy('cf.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findActiveForClient(Client $client): ?ComplianceFolder
    {
        return $this->repository->createQueryBuilder('cf')
            ->select('cf') // Sélection explicite
            ->where('cf.client = :client')
            ->andWhere('cf.status IN (:kycStatuses)')
            ->setParameter('client', $client)
            ->setParameter('kycStatuses', ComplianceFolderStatus::getKycPhaseStatuses())
            ->orderBy('cf.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOneBySlugIdAndClient(string $folderId, Client $client): ?ComplianceFolder
    {
        return $this->repository->findOneBy(['slugId' => $folderId, 'client' => $client]);
    }

    public function findOneBySlugId(string $slugId): ?ComplianceFolder
    {
        return $this->repository->findOneBy(['slugId' => $slugId]);
    }
}
