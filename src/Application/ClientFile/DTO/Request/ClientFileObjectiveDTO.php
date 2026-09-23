<?php

declare(strict_types=1);

namespace App\Application\ClientFile\DTO\Request;

/**
 * Un emplacement d'objectif d'investissement dans le formulaire (3 emplacements fixes, cf.
 * {@see UpdateClientFileRequest}). `type` à null signifie un emplacement laissé vide par le
 * conseiller — l'objectif correspondant est alors simplement ignoré, pas d'erreur de validation.
 */
class ClientFileObjectiveDTO
{
    public ?string $type = null;

    public ?string $priority = null;

    public ?string $horizon = null;

    public ?int $amount = null;
}
