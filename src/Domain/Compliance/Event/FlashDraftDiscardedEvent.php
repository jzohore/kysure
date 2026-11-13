<?php

declare(strict_types=1);

namespace App\Domain\Compliance\Event;

/**
 * Un brouillon ouvert par le client via le QR code public du cabinet a été
 * abandonné (« Retour au choix »). Aucun utilisateur n'est connecté : l'acteur
 * est un libellé système, d'où un événement distinct de DeleteComplianceEvent
 * (dont le listener d'audit exige un User en base).
 */
final readonly class FlashDraftDiscardedEvent
{
    public function __construct(
        public string $folderSlugId,
        public string $actorName,
        public string $reason,
    ) {
    }
}
