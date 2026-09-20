<?php

declare(strict_types=1);

namespace App\Application\Suitability\UseCase;

use App\Domain\Suitability\Entity\ValidatedInvestorProfile;
use App\Domain\Suitability\Repository\ValidatedInvestorProfileRepositoryInterface;
use App\Domain\Workspace\Entity\Workspace;

/**
 * Résout un profil investisseur validé par son slug, scopé au cabinet courant — intermédiaire
 * obligatoire pour que les contrôleurs n'accèdent jamais directement au repository (règle
 * deptrac Controller -> Repository interdit).
 */
final readonly class FindValidatedInvestorProfileForCabinetUseCase
{
    public function __construct(
        private ValidatedInvestorProfileRepositoryInterface $profileRepository,
    ) {
    }

    public function __invoke(string $slugId, Workspace $workspace): ?ValidatedInvestorProfile
    {
        return $this->profileRepository->findBySlugIdAndWorkspace($slugId, $workspace);
    }
}
