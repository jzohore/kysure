<?php

declare(strict_types=1);

namespace App\Application\Firm\UseCase;

use App\Application\Firm\DTO\Request\UpdateRegulatoryProfileRequest;
use App\Domain\User\Entity\User;
use App\Domain\User\Repository\UserRepositoryInterface;

/**
 * Enregistre le profil réglementaire saisi pendant l'onboarding puis fait avancer l'étape.
 *
 * Réutilise {@see UpdateRegulatoryProfileUseCase} (contrôle des droits + événement de mise à jour) :
 * on ne duplique aucune règle de saisie. Rejouable : si l'avancement échoue après l'enregistrement,
 * l'utilisateur re-soumet le formulaire sans effet de bord.
 */
readonly class CompleteRegulatoryProfileOnboardingUseCase
{
    public function __construct(
        private UpdateRegulatoryProfileUseCase $updateRegulatoryProfile,
        private UserRepositoryInterface $userRepository,
    ) {
    }

    /**
     * @throws \DomainException si le type de compte n'a pas encore été choisi
     */
    public function __invoke(UpdateRegulatoryProfileRequest $request, User $user): void
    {
        ($this->updateRegulatoryProfile)($request);

        if ($user->completeRegulatoryProfileStep()) {
            $this->userRepository->save($user);
        }
    }
}
