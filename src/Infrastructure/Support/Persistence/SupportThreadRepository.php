<?php

declare(strict_types=1);

namespace App\Infrastructure\Support\Persistence;

use App\Application\Support\DTO\Response\SupportNotificationStats;
use App\Domain\Support\Entity\SupportThread;
use App\Domain\Support\Enum\SupportSenderType;
use App\Domain\Support\Enum\SupportThreadStatus;
use App\Domain\Support\Repository\SupportThreadRepositoryInterface;
use App\Domain\User\Entity\User;
use App\Domain\Workspace\Entity\Workspace;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Pagerfanta\Doctrine\ORM\QueryAdapter;
use Pagerfanta\Pagerfanta;
use Symfony\Component\Uid\Uuid;

/**
 * @method SupportThread|null find($id, $lockMode = null, $lockVersion = null)
 * @method SupportThread|null findOneBy(array<string, mixed> $criteria, array<string, string>|null $orderBy = null)
 * @method SupportThread[]    findAll()
 * @method SupportThread[]    findBy(array<string, mixed> $criteria, array<string, string>|null $orderBy = null, $limit = null, $offset = null)
 */
readonly class SupportThreadRepository implements SupportThreadRepositoryInterface
{
    /** @var EntityRepository<SupportThread> */
    private EntityRepository $repository;

    public function __construct(private EntityManagerInterface $entityManager)
    {
        $this->repository = $entityManager->getRepository(SupportThread::class);
    }

    public function findActiveThreadForUser(Workspace $workspace, User $user): ?SupportThread
    {
        return $this->repository->createQueryBuilder('st')
            ->where('st.workspace = :workspace')
            ->andWhere('st.user = :user')
            ->andWhere('st.status = :status')
            ->setParameter('workspace', $workspace)
            ->setParameter('user', $user)
            ->setParameter('status', SupportThreadStatus::OPEN)
            ->orderBy('st.updatedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findById(Uuid $id): ?SupportThread
    {
        return $this->find($id);
    }

    public function save(SupportThread $thread): void
    {
        $this->entityManager->persist($thread);
        $this->entityManager->flush();
    }

    public function delete(SupportThread $thread): void
    {
        $this->entityManager->remove($thread);
    }

    /**
     * Renvoie les statistiques de notifications en une seule requête ultra-légère.
     */
    public function getNotificationStats(Workspace $workspace, User $user): SupportNotificationStats
    {
        $result = $this->repository->createQueryBuilder('st')
            ->select('st.id', 'COUNT(sm.id) AS unread_count')
            // 🛡️ LE FIX EST ICI : On vérifie que readAt EST NULL au lieu de isRead = false
            ->leftJoin(
                'st.messages',
                'sm',
                'WITH',
                'sm.senderType = :senderAdmin AND sm.readAt IS NULL'
            )
            ->where('st.workspace = :workspace')
            ->andWhere('st.user = :user')
            ->andWhere('st.status = :statusOpen')
            ->setParameter('workspace', $workspace)
            ->setParameter('user', $user)
            ->setParameter('statusOpen', SupportThreadStatus::OPEN->value)
            ->setParameter('senderAdmin', SupportSenderType::ADMIN->value)
            ->groupBy('st.id')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
        // S'il n'y a aucun thread ouvert, on renvoie false et 0
        if (!$result) {
            return new SupportNotificationStats(false, 0);
        }

        // Sinon on renvoie true et le compte des messages non lus
        return new SupportNotificationStats(true, (int) $result['unread_count']);
    }

    /**
     * @return Pagerfanta<SupportThread>
     */
    public function getPaginatedSupport(?string $search = null, ?SupportThreadStatus $statusFilter = null): Pagerfanta
    {
        $qb = $this->repository->createQueryBuilder('st')
            // Optimisation : Chargement des relations pour éviter le N+1
            ->select('st', 'workspace', 'user')
            ->leftJoin('st.workspace', 'workspace')
            ->leftJoin('st.user', 'user')
            // UX : Les derniers tickets mis à jour (réponses) remontent en premier
            ->orderBy('st.updatedAt', 'DESC');

        if (!in_array($search, [null, '', '0'], true)) {
            $qb->andWhere('st.topic LIKE :search OR st.category LIKE :search OR user.email LIKE :search OR user.firstName LIKE :search')
                ->setParameter('search', '%' . trim($search) . '%');
        }

        if ($statusFilter instanceof SupportThreadStatus) {
            $qb->andWhere('st.status = :status')
                ->setParameter('status', $statusFilter); // Doctrine transforme automatiquement l'Enum en string grâce à enumType
        }

        return new Pagerfanta(new QueryAdapter($qb));
    }

    /**
     * @return Pagerfanta<SupportThread>
     */
    public function getPaginatedSupportForWorkspace(Workspace $workspace, ?SupportThreadStatus $statusFilter = null): Pagerfanta
    {
        $qb = $this->repository->createQueryBuilder('st')
            ->where('st.workspace = :workspace')
            ->setParameter('workspace', $workspace)
            ->orderBy('st.updatedAt', 'DESC');

        if ($statusFilter instanceof SupportThreadStatus) {
            $qb->andWhere('st.status = :status')
                ->setParameter('status', $statusFilter);
        }

        return new Pagerfanta(new QueryAdapter($qb));
    }

    public function countAllOpenTickets(): int
    {
        return (int) $this->repository->createQueryBuilder('st')
            ->select('COUNT(st.id)')
            ->where('st.status = :status')
            ->setParameter('status', SupportThreadStatus::OPEN->value)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function refresh(SupportThread $thread): void
    {
        $this->entityManager->refresh($thread);
    }

    /**
     * @return SupportThread[]
     */
    public function findInactiveOpenThreads(\DateTimeImmutable $before): array
    {
        return $this->repository->createQueryBuilder('st')
            // Optimisation : on charge les messages directement pour éviter le N+1 dans le Use Case
            ->addSelect('messages')
            ->leftJoin('st.messages', 'messages')
            ->where('st.status = :status')
            ->andWhere('st.updatedAt <= :before')
            ->setParameter('status', SupportThreadStatus::OPEN)
            ->setParameter('before', $before)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return SupportThread[]
     */
    public function findInactiveThreadsForWarning(\DateTimeInterface $threshold): array
    {
        return $this->repository->createQueryBuilder('st')
            // On s'assure de ne prendre que les tickets ouverts
            ->where('st.status = :status')
            // Dont la dernière mise à jour est plus ancienne que la limite
            ->andWhere('st.updatedAt <= :threshold')
            // Et qui n'ont PAS encore reçu le message d'avertissement
            ->andWhere('st.closureWarningSent = :warningSent')
            ->setParameter('status', SupportThreadStatus::OPEN)
            ->setParameter('warningSent', false)
            ->setParameter('threshold', $threshold)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return SupportThread[]
     */
    public function findThreadsPendingClosure(\DateTimeInterface $threshold): array
    {
        return $this->repository->createQueryBuilder('st')
            ->where('st.status = :status')
            ->andWhere('st.updatedAt <= :threshold')
            // Cette fois, on cible ceux qui ONT DEJA reçu l'avertissement
            ->andWhere('st.closureWarningSent = :warningSent')
            ->setParameter('status', SupportThreadStatus::OPEN)
            ->setParameter('warningSent', true)
            ->setParameter('threshold', $threshold)
            ->getQuery()
            ->getResult();
    }
}
