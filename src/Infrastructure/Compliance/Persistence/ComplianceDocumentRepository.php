<?php

declare(strict_types=1);

namespace App\Infrastructure\Compliance\Persistence;

use App\Domain\Compliance\Entity\ComplianceDocument;
use App\Domain\Compliance\Entity\ComplianceFolder;
use App\Domain\Compliance\Enum\DocumentType;
use App\Domain\Compliance\Repository\ComplianceDocumentRepositoryInterface;
use App\Domain\Kyc\Enum\DocumentStatus;
use App\Domain\User\Entity\Client;
use App\Domain\Workspace\Entity\Workspace;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Symfony\Component\Uid\Uuid;

/**
 * @method ComplianceDocument|null find($id, $lockMode = null, $lockVersion = null)
 * @method ComplianceDocument|null findOneBy(array<string, mixed> $criteria, array<string, string>|null $orderBy = null)
 * @method ComplianceDocument[]    findAll()
 * @method ComplianceDocument[]    findBy(array<string, mixed> $criteria, array<string, string>|null $orderBy = null, $limit = null, $offset = null)
 */
class ComplianceDocumentRepository implements ComplianceDocumentRepositoryInterface
{
    /** @var EntityRepository<ComplianceDocument> */
    private readonly EntityRepository $repository;

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
        $this->repository = $entityManager->getRepository(ComplianceDocument::class);
    }

    public function findById(Uuid|string $id): ?ComplianceDocument
    {
        return $this->repository->find($id);
    }

    public function findByStatus(string $status, Workspace $workspace): array
    {
        return $this->repository->findBy(['status' => $status, 'workspace' => $workspace]);
    }

    public function existsForFolder(Uuid|string $folderId, DocumentType $type): bool
    {
        return $this->repository->count(['folder' => $folderId, 'type' => $type]) > 0;
    }

    public function save(ComplianceDocument $document, bool $flush = true): void
    {
        $this->entityManager->persist($document);
        if ($flush) {
            $this->entityManager->flush();
        }
    }

    public function remove(ComplianceDocument $document, bool $flush = true): void
    {
        $this->entityManager->remove($document);
        if ($flush) {
            $this->entityManager->flush();
        }
    }

    public function existsForFolderAndType(ComplianceFolder $folder, DocumentType $type): bool
    {
        return $this->repository->count(['folder' => $folder, 'type' => $type]) > 0;
    }

    public function findDerByFolder(ComplianceFolder $folder): ?ComplianceDocument
    {
        return $this->repository->createQueryBuilder('d')
            ->andWhere('d.folder = :folder')
            ->andWhere('d.type = :type')
            ->setParameter('folder', $folder)
            ->setParameter('type', DocumentType::DER) // ou 'DER' si ton type est une simple string
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOneByAcknowledgementTokenHash(string $tokenHash): ?ComplianceDocument
    {
        return $this->repository->findOneBy(['acknowledgementTokenHash' => $tokenHash]);
    }

    public function countPendingForClient(Client $client): int
    {
        $qb = $this->repository->createQueryBuilder('cd');

        return (int) $qb
            ->select('COUNT(cd.id)')
            ->innerJoin('cd.folder', 'cf')
            // Ensuite, on filtre sur le client lié à ce dossier
            ->where('cf.client = :client')
            ->andWhere('cd.status IN (:statuses)')
            ->andWhere('cd.isAskToClient = true')
            ->setParameter('client', $client)
            ->setParameter('statuses', DocumentStatus::getActionableByClientStatuses())
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countPendingForFolder(ComplianceFolder $folder): int
    {
        return (int) $this->repository->createQueryBuilder('cd')
            ->select('COUNT(cd.id)')
            ->where('cd.folder = :folder')
            ->andWhere('cd.status IN (:statuses)')
            ->andWhere('cd.isAskToClient = true')
            ->setParameter('folder', $folder)
            ->setParameter('statuses', DocumentStatus::getActionableByClientStatuses())
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return array<ComplianceDocument>
     */
    public function findByFolder(ComplianceFolder $folder): array
    {
        return $this->repository->findBy(['folder' => $folder, 'isAskToClient' => true]);
    }
}
