<?php

declare(strict_types=1);

namespace App\Domain\Firm\Event;

use App\Domain\User\Entity\User;
use App\Domain\Workspace\Entity\Workspace;

/**
 * Le conseiller a choisi de reporter la saisie de son profil réglementaire pendant l'onboarding.
 * Conséquence assumée : aucun DER ne peut être généré tant que ce profil n'est pas complet.
 */
readonly class RegulatoryProfileDeferredEvent
{
    public function __construct(
        public User $user,
        public Workspace $workspace,
    ) {
    }
}
