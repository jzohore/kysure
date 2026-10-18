<?php

declare(strict_types=1);

namespace App\Domain\User\Enum;

enum OnboardingStatus: string
{
    case PENDING = 'pending';           // Vient de cliquer sur le lien magique
    case WORKSPACE_SETUP = 'workspace';
    case PROFIL_SETUP = 'profil';
    case PLAN_SETUP = 'plan';
    case REGULATORY_PROFILE_SETUP = 'regulatory_profile'; // Profil réglementaire renseigné OU explicitement reporté
    case COMPLETED = 'completed';       // A terminé, accès total
}
