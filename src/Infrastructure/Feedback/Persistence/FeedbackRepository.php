<?php

declare(strict_types=1);

namespace App\Infrastructure\Feedback\Persistence;

use App\Domain\Feedback\Entity\Feedback;
use App\Domain\Feedback\Repository\FeedbackRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;

/**
 * @method Feedback|null find($id, $lockMode = null, $lockVersion = null)
 * @method Feedback|null findOneBy(array<string, mixed> $criteria, array<string, string>|null $orderBy = null)
 * @method Feedback[]    findAll()
 * @method Feedback[]    findBy(array<string, mixed> $criteria, array<string, string>|null $orderBy = null, $limit = null, $offset = null)
 */
readonly class FeedbackRepository implements FeedbackRepositoryInterface
{
    /** @var EntityRepository<Feedback> */
    private EntityRepository $repository;

    public function __construct(private EntityManagerInterface $entityManager)
    {
        $this->repository = $entityManager->getRepository(Feedback::class);
    }

    public function save(Feedback $feedback): void
    {
        $this->entityManager->persist($feedback);
        $this->entityManager->flush();
    }

    public function findAllNewestFirst(): array
    {
        return $this->repository->findBy([], ['createdAt' => 'DESC']);
    }
}
