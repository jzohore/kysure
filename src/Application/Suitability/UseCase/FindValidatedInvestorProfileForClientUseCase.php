<?php

declare(strict_types=1);

namespace App\Application\Suitability\UseCase;

use App\Domain\Suitability\Entity\ValidatedInvestorProfile;
use App\Domain\Suitability\Repository\ValidatedInvestorProfileRepositoryInterface;
use App\Domain\User\Entity\Client;

/**
 * Résout un profil investisseur validé par son slug, uniquement s'il appartient bien au client
 * demandeur — intermédiaire obligatoire pour que les contrôleurs n'accèdent jamais directement
 * au repository (règle deptrac Controller -> Repository interdit).
 */
final readonly class FindValidatedInvestorProfileForClientUseCase
{
    public function __construct(
        private ValidatedInvestorProfileRepositoryInterface $profileRepository,
    ) {
    }

    public function __invoke(string $slugId, Client $client): ?ValidatedInvestorProfile
    {
        $profile = $this->profileRepository->findBySlugId($slugId);

        return $profile instanceof ValidatedInvestorProfile && $profile->client === $client ? $profile : null;
    }
}
