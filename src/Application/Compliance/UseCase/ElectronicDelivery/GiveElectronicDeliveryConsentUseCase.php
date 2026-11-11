<?php

declare(strict_types=1);

namespace App\Application\Compliance\UseCase\ElectronicDelivery;

use App\Domain\Compliance\Entity\ComplianceFolder;
use App\Domain\Compliance\Entity\ElectronicDeliveryConsent;
use App\Domain\Compliance\Event\ElectronicDeliveryConsentGivenEvent;
use App\Domain\Compliance\Exception\ComplianceFolderNotFoundException;
use App\Domain\Compliance\Repository\ComplianceFolderRepositoryInterface;
use App\Domain\Compliance\Repository\ElectronicDeliveryConsentRepositoryInterface;
use App\Domain\Compliance\ValueObject\ElectronicDeliveryConsentStatement;
use App\Domain\Database\TransactionManagerInterface;
use App\Domain\User\Entity\Client;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Le client accepte la remise électronique depuis son espace (avenant au DER). Idempotent :
 * un double envoi du formulaire ne crée pas un second accord.
 */
final readonly class GiveElectronicDeliveryConsentUseCase
{
    public function __construct(
        private ComplianceFolderRepositoryInterface $folderRepository,
        private ElectronicDeliveryConsentRepositoryInterface $consentRepository,
        private ElectronicDeliveryConsentResolver $resolver,
        private TransactionManagerInterface $transactionManager,
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function __invoke(Client $client, string $folderSlugId): void
    {
        // La propriété du dossier (et non un simple rôle) protège l'accès.
        $folder = $this->folderRepository->findOneBySlugIdAndClient($folderSlugId, $client);
        if (!$folder instanceof ComplianceFolder) {
            throw ComplianceFolderNotFoundException::withId($folderSlugId);
        }

        if (null !== $this->resolver->resolve($folder)) {
            return;
        }

        $consent = ElectronicDeliveryConsent::give($folder, $client, ElectronicDeliveryConsentStatement::current());
        $folder->saveHistory('Accord de remise électronique donné', 'Le client a accepté la remise électronique de ses documents (avenant au DER) depuis son espace.');

        $this->transactionManager->transactional(function () use ($consent, $folder): void {
            $this->consentRepository->save($consent, flush: false);
            $this->folderRepository->save($folder);
        });

        $this->eventDispatcher->dispatch(new ElectronicDeliveryConsentGivenEvent(
            consentSlugId: $consent->slugId,
            folderSlugId: $folder->slugId,
            clientSlugId: $client->slugId,
            statementVersion: $consent->statementVersion,
        ));
    }
}
