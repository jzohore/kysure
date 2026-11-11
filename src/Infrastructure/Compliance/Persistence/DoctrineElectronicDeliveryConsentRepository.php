<?php

declare(strict_types=1);

namespace App\Infrastructure\Compliance\Persistence;

use App\Domain\Compliance\Entity\ComplianceFolder;
use App\Domain\Compliance\Entity\ElectronicDeliveryConsent;
use App\Domain\Compliance\Repository\ElectronicDeliveryConsentRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;

readonly class DoctrineElectronicDeliveryConsentRepository implements ElectronicDeliveryConsentRepositoryInterface
{
    /** @var EntityRepository<ElectronicDeliveryConsent> */
    private EntityRepository $repository;

    public function __construct(private EntityManagerInterface $entityManager)
    {
        $this->repository = $entityManager->getRepository(ElectronicDeliveryConsent::class);
    }

    public function save(ElectronicDeliveryConsent $consent, bool $flush = true): void
    {
        $this->entityManager->persist($consent);

        if ($flush) {
            $this->entityManager->flush();
        }
    }

    public function findLatestByFolder(ComplianceFolder $folder): ?ElectronicDeliveryConsent
    {
        return $this->repository->findOneBy(['complianceFolder' => $folder], ['consentedAt' => 'DESC']);
    }
}
