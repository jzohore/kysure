<?php

declare(strict_types=1);

namespace App\Application\Compliance\UseCase\ComplianceFolder;

use App\Domain\Compliance\Enum\ComplianceFolderStatus;
use App\Domain\Compliance\Enum\FolderCreationMethod;
use App\Domain\Compliance\Event\FlashDraftDiscardedEvent;
use App\Domain\Compliance\Exception\ComplianceFolderNotFoundException;
use App\Domain\Compliance\Repository\ComplianceFolderRepositoryInterface;
use App\Domain\Workspace\Entity\Workspace;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Abandon d'un brouillon ouvert par le client sur la page publique du QR code
 * (`/onboarding/{publicToken}`), sans utilisateur connecté.
 *
 * Gardes (dans cet ordre) :
 *  1. le dossier appartient au workspace du token — sinon on ne révèle rien ;
 *  2. il a bien été ouvert par le canal flash ;
 *  3. il est encore un brouillon vierge (aucune preuve de vigilance).
 *
 * Idempotent : identifiant invalide, dossier absent ou déjà supprimé = no-op.
 */
final readonly class DiscardFlashDraftUseCase
{
    public const string SYSTEM_ACTOR = 'SYSTEM_FLASH_ONBOARDING';

    private const string REASON = 'Brouillon abandonné par le client sur le parcours QR code.';

    public function __construct(
        private ComplianceFolderRepositoryInterface $folderRepository,
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    /**
     * @return bool vrai si le brouillon vient d'être supprimé, faux si rien n'était à faire
     *
     * @throws \DomainException si le dossier existe mais ne peut pas être abandonné par ce canal
     */
    public function __invoke(string $folderId, Workspace $workspace): bool
    {
        if (!Uuid::isValid($folderId)) {
            return false;
        }

        try {
            $folder = $this->folderRepository->findById($folderId);
        } catch (ComplianceFolderNotFoundException) {
            return false;
        }

        if ($folder->workspace->slugId !== $workspace->slugId) {
            throw new \DomainException('Ce brouillon n\'appartient pas au cabinet de ce QR code.');
        }

        if (FolderCreationMethod::FLASH_QR_CODE->value !== $folder->creationMethod) {
            throw new \DomainException('Seul un brouillon ouvert via le QR code peut être abandonné ici.');
        }

        // Rejeu (double clic, retry LiveComponent) : déjà fait, rien à tracer de plus.
        if (ComplianceFolderStatus::DELETED === $folder->status) {
            return false;
        }

        if (!$folder->isDraft() || $folder->carriesEvidence()) {
            throw new \DomainException('Ce dossier porte déjà une trace de vigilance : il ne peut plus être abandonné.');
        }

        $folder->markAsDeleted(self::REASON, self::SYSTEM_ACTOR);
        $this->folderRepository->save($folder);

        $this->eventDispatcher->dispatch(new FlashDraftDiscardedEvent(
            folderSlugId: $folder->slugId,
            actorName: self::SYSTEM_ACTOR,
            reason: self::REASON,
        ));

        return true;
    }
}
