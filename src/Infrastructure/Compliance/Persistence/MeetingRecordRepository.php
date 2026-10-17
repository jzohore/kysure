<?php

declare(strict_types=1);

namespace App\Infrastructure\Compliance\Persistence;

use App\Domain\Compliance\Entity\ComplianceFolder;
use App\Domain\Compliance\Entity\MeetingRecording;
use App\Domain\Compliance\Repository\MeetingRecordRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Symfony\Component\Uid\Uuid;

/**
 * @method MeetingRecording|null find($id, $lockMode = null, $lockVersion = null)
 * @method MeetingRecording|null findOneBy(array<string, mixed> $criteria, array<string, string>|null $orderBy = null)
 * @method MeetingRecording[]    findAll()
 * @method MeetingRecording[]    findBy(array<string, mixed> $criteria, array<string, string>|null $orderBy = null, $limit = null, $offset = null)
 */
readonly class MeetingRecordRepository implements MeetingRecordRepositoryInterface
{
    /** @var EntityRepository<MeetingRecording> */
    private EntityRepository $repository;

    public function __construct(private EntityManagerInterface $entityManager)
    {
        $this->repository = $entityManager->getRepository(MeetingRecording::class);
    }

    public function save(MeetingRecording $meetingRecording, bool $flush = true): void
    {
        $this->entityManager->persist($meetingRecording);
        if ($flush) {
            $this->entityManager->flush();
        }
    }

    public function remove(MeetingRecording $meetingRecording, bool $flush = true): void
    {
        $this->entityManager->persist($meetingRecording);
        if ($flush) {
            $this->entityManager->flush();
        }
    }

    public function findById(Uuid|string $id): ?MeetingRecording
    {
        return $this->repository->find($id);
    }

    public function existsForSession(ComplianceFolder $complianceFolder, string $sessionId): bool
    {
        return null !== $this->repository->findOneBy([
            'complianceFolder' => $complianceFolder,
            'sessionId' => $sessionId,
        ]);
    }

    public function findAllByFolder(ComplianceFolder $complianceFolder): array
    {
        return $this->repository->findBy(['complianceFolder' => $complianceFolder]);
    }

    public function findBySlugId(string $slugId): ?MeetingRecording
    {
        return $this->repository->findOneBy(['slugId' => $slugId]);
    }

    public function findActiveByFolder(ComplianceFolder $complianceFolder): array
    {
        return $this->repository->createQueryBuilder('mr')
            ->andWhere('mr.complianceFolder = :compliance_folder')
            ->andWhere('mr.audioDeletedAt IS NULL')
            ->orderBy('mr.recordedAt', 'ASC')
            ->setParameter('compliance_folder', $complianceFolder)
            ->getQuery()
            ->getResult();
    }
}
