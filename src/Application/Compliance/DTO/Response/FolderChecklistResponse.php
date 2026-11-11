<?php

declare(strict_types=1);

namespace App\Application\Compliance\DTO\Response;

/**
 * Checklist de pilotage d'un dossier, dérivée de l'état des entités existantes (lot 8) :
 * « où j'en suis », jamais un état formel stocké.
 */
final readonly class FolderChecklistResponse
{
    /**
     * @param list<FolderChecklistItem> $items dans l'ordre du parcours
     */
    public function __construct(
        public array $items,
    ) {
    }

    public function doneCount(): int
    {
        return \count(array_filter($this->items, static fn (FolderChecklistItem $item): bool => $item->done));
    }

    public function totalCount(): int
    {
        return \count($this->items);
    }

    /** La première étape restante : celle sur laquelle attirer l'attention. */
    public function nextItem(): ?FolderChecklistItem
    {
        foreach ($this->items as $item) {
            if (!$item->done) {
                return $item;
            }
        }

        return null;
    }
}
