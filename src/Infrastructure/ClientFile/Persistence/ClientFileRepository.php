<?php

declare(strict_types=1);

namespace App\Infrastructure\ClientFile\Persistence;

use App\Domain\ClientFile\Entity\ClientFile;
use App\Domain\ClientFile\Repository\ClientFileRepositoryInterface;
use App\Domain\Compliance\Entity\ComplianceFolder;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;

/**
 * @method ClientFile|null find($id, $lockMode = null, $lockVersion = null)
 * @method ClientFile|null findOneBy(array<string, mixed> $criteria, array<string, string>|null $orderBy = null)
 * @method ClientFile[]    findAll()
 * @method ClientFile[]    findBy(array<string, mixed> $criteria, array<string, string>|null $orderBy = null, $limit = null, $offset = null)
 */
readonly class ClientFileRepository implements ClientFileRepositoryInterface
{
    /** @var EntityRepository<ClientFile> */
    private EntityRepository $repository;

    public function __construct(private EntityManagerInterface $entityManager)
    {
        $this->repository = $entityManager->getRepository(ClientFile::class);
    }

    public function findByComplianceFolder(ComplianceFolder $complianceFolder): ?ClientFile
    {
        return $this->repository->findOneBy(['complianceFolder' => $complianceFolder]);
    }

    public function save(ClientFile $clientFile): void
    {
        $this->entityManager->persist($clientFile);
        $this->entityManager->flush();
    }
}
