<?php

declare(strict_types=1);

namespace App\Application\Firm\UseCase;

use App\Domain\Firm\Event\RegulatoryProfileDeferredEvent;
use App\Domain\User\Entity\User;
use App\Domain\User\Repository\UserRepositoryInterface;
use App\Domain\Workspace\Entity\WorkspaceMember;
use App\Domain\Workspace\Repository\WorkspaceMemberRepositoryInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Webmozart\Assert\Assert;

/**
 * « Je le ferai plus tard » : le conseiller passe l'étape profil réglementaire de l'onboarding.
 *
 * Choix tracé (audit) car il a une conséquence métier : tant que le profil n'est pas complet,
 * la génération du DER reste bloquée. Idempotent : un double clic ne rejoue ni l'audit ni l'événement.
 */
readonly class DeferRegulatoryProfileOnboardingUseCase
{
    public function __construct(
        private UserRepositoryInterface $userRepository,
        private WorkspaceMemberRepositoryInterface $workspaceMemberRepository,
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    /**
     * @throws \DomainException si le type de compte n'a pas encore été choisi
     */
    public function __invoke(User $user): void
    {
        Assert::notNull($user->id);

        $member = $this->workspaceMemberRepository->findOneByUser($user->id);
        Assert::isInstanceOf(
            $member,
            WorkspaceMember::class,
            'Impossible de reporter le profil réglementaire : aucun espace de travail rattaché à l\'utilisateur.',
        );

        // 🛡️ Idempotence : étape déjà passée → rien à faire, rien à journaliser.
        if (!$user->completeRegulatoryProfileStep()) {
            return;
        }

        $this->userRepository->save($user);

        $this->eventDispatcher->dispatch(new RegulatoryProfileDeferredEvent($user, $member->workspace));
    }
}
