<?php

declare(strict_types=1);

namespace App\Application\Compliance\UseCase\ElectronicDelivery;

use App\Domain\Compliance\Entity\ComplianceFolder;
use App\Domain\Compliance\Event\ElectronicDeliveryConsentRequestedEvent;
use App\Domain\Compliance\Repository\ComplianceFolderRepositoryInterface;
use App\Domain\Workspace\Service\CurrentUserProvider;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Le CGP demande au client, par e-mail, d'accepter la remise électronique dans son espace
 * (avenant au DER). Réservé au client qui a accusé un ancien DER : le DER accusé reste figé
 * et le statut du dossier ne bouge pas. L'autorisation relève du voter, vérifiée par l'appelant.
 */
final readonly class RequestElectronicDeliveryConsentUseCase
{
    public function __construct(
        private ElectronicDeliveryConsentResolver $resolver,
        private ComplianceFolderRepositoryInterface $folderRepository,
        private CurrentUserProvider $userProvider,
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function __invoke(ComplianceFolder $folder): void
    {
        if (!$this->resolver->needsAmendment($folder)) {
            throw new \DomainException('Aucun accord à demander : le client l\'a déjà donné, ou n\'a pas encore accusé réception du DER (l\'accord est alors recueilli avec le DER).');
        }

        $user = $this->userProvider->getUser();

        // Garde anti-rafale (24 h) portée par l'entité.
        $folder->requestElectronicDeliveryConsent($user->getFullName());
        $this->folderRepository->save($folder);

        $this->eventDispatcher->dispatch(new ElectronicDeliveryConsentRequestedEvent(
            folderSlugId: $folder->slugId,
            requestedByName: $user->getFullName(),
        ));
    }
}
