<?php

declare(strict_types=1);

namespace App\Domain\Compliance\Event;

/**
 * Le dossier a été soumis pour analyse (il passe « en revue ») : c'est le signal que le CGP
 * peut prendre sa décision de conformité.
 */
final readonly class ComplianceFolderSubmittedEvent
{
    public const string ACTOR_CLIENT = 'client';
    public const string ACTOR_CGP = 'cgp';

    public function __construct(
        public string $folderSlugId,
        /** Qui a soumis : le client depuis son espace, ou le CGP depuis l'application. */
        public string $actorType,
        public ?string $actorName,
    ) {
    }
}
