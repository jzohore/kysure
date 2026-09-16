<?php

declare(strict_types=1);

namespace App\Application\Suitability\UseCase;

use App\Domain\Database\TransactionManagerInterface;
use App\Domain\Suitability\Entity\ValidatedInvestorProfile;
use App\Domain\Suitability\Event\InvestorProfileRevokedEvent;
use App\Domain\Suitability\Repository\ValidatedInvestorProfileRepositoryInterface;
use App\Domain\Workspace\Service\CurrentUserProvider;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Webmozart\Assert\Assert;

/**
 * Révoque une version figée du profil investisseur : elle reste consultable et archivée, mais
 * n'est plus en vigueur. Ouvre la voie à une nouvelle validation (version + 1), typiquement
 * après un nouveau passage du questionnaire par le client.
 *
 * L'autorisation « CGP responsable » relève d'un voter au niveau du contrôleur/composant ;
 * ce use case se limite aux règles métier.
 */
final readonly class RevokeInvestorProfileUseCase
{
    public function __construct(
        private ValidatedInvestorProfileRepositoryInterface $profileRepository,
        private TransactionManagerInterface $transactionManager,
        private CurrentUserProvider $userProvider,
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function __invoke(string $profileSlugId, string $reason): void
    {
        $profile = $this->profileRepository->findBySlugId($profileSlugId);

        if (!$profile instanceof ValidatedInvestorProfile) {
            throw new \DomainException('Profil investisseur introuvable.');
        }

        $user = $this->userProvider->getUser();
        $reason = trim($reason);

        // Gardes métier (déjà révoqué / motif vide) portées par l'entité : au retour, $reason
        // est garanti non vide et identique à $profile->revokeReason.
        $profile->revoke($user, $reason);

        $this->transactionManager->transactional(function () use ($profile): void {
            $this->profileRepository->save($profile);
        });

        Assert::notNull($profile->id);
        $this->eventDispatcher->dispatch(new InvestorProfileRevokedEvent(
            profileSlugId: $profile->slugId,
            clientSlugId: $profile->client->slugId,
            version: $profile->version,
            reason: $reason,
            revokedByName: $user->getFullName(),
        ));
    }
}
