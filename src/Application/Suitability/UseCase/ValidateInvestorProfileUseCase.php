<?php

declare(strict_types=1);

namespace App\Application\Suitability\UseCase;

use App\Domain\Database\TransactionManagerInterface;
use App\Domain\Suitability\Entity\InvestorProfileAssessment;
use App\Domain\Suitability\Entity\ValidatedInvestorProfile;
use App\Domain\Suitability\Event\InvestorProfileValidatedEvent;
use App\Domain\Suitability\Repository\ValidatedInvestorProfileRepositoryInterface;
use App\Domain\Suitability\Service\TraderWithoutSafetyNetDetector;
use App\Domain\Workspace\Service\CurrentUserProvider;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Webmozart\Assert\Assert;

/**
 * Fige le profil investisseur calculé d'un client tel que validé par le CGP : à partir de cet
 * instant il ne bouge plus, quelles que soient les évolutions de l'assessment source (une
 * nouvelle tentative du client après correction ne le modifie pas rétroactivement).
 *
 * Le CGP peut corriger le profil calculé par l'algorithme (`$overriddenProfileLevel`), avec
 * motif obligatoire — jamais un simple clic : voir {@see ValidatedInvestorProfile::validate()}.
 * Le profil calculé initial reste toujours accessible ({@see ValidatedInvestorProfile::computedProfileLevel()}),
 * il n'est jamais écrasé par la correction.
 *
 * L'autorisation « CGP responsable » relève d'un voter au niveau du contrôleur/composant ;
 * ce use case se limite aux règles métier.
 */
final readonly class ValidateInvestorProfileUseCase
{
    public function __construct(
        private ValidatedInvestorProfileRepositoryInterface $profileRepository,
        private TransactionManagerInterface $transactionManager,
        private CurrentUserProvider $userProvider,
        private EventDispatcherInterface $eventDispatcher,
        private TraderWithoutSafetyNetDetector $traderWithoutSafetyNetDetector,
    ) {
    }

    public function __invoke(
        InvestorProfileAssessment $assessment,
        ?int $overriddenProfileLevel = null,
        ?string $overrideReason = null,
    ): string {
        if (!$assessment->isSubmitted()) {
            throw new \DomainException('Le questionnaire n\'a pas encore été soumis par le client.');
        }

        if ($this->profileRepository->findInForceByClient($assessment->client, $assessment->workspace) instanceof ValidatedInvestorProfile) {
            throw new \DomainException('Un profil investisseur est déjà validé pour ce client dans ce cabinet. Révoquez-le avant d\'en valider un nouveau.');
        }

        $user = $this->userProvider->getUser();
        $version = $this->profileRepository->findLatestVersionNumber($assessment->client, $assessment->workspace) + 1;

        $profile = ValidatedInvestorProfile::validate(
            workspace: $assessment->workspace,
            client: $assessment->client,
            assessment: $assessment,
            validatedBy: $user,
            content: [
                'answers' => $assessment->answersAsMap(),
                'scoreSnapshot' => $assessment->scoreSnapshot ?? [],
            ],
            version: $version,
            overriddenProfileLevel: $overriddenProfileLevel,
            overrideReason: $overrideReason,
        );

        $this->transactionManager->transactional(function () use ($profile): void {
            $this->profileRepository->save($profile);
        });

        Assert::notNull($profile->id);
        $this->eventDispatcher->dispatch(new InvestorProfileValidatedEvent(
            profileSlugId: $profile->slugId,
            clientSlugId: $assessment->client->slugId,
            version: $version,
            retainedProfileLevel: $profile->retainedProfileLevel(),
            overridden: $profile->isOverridden(),
            validatedByName: $user->getFullName(),
            hasHighRiskLowCapacityMismatch: $this->traderWithoutSafetyNetDetector->detect($assessment->scoreSnapshot ?? []),
        ));

        return $profile->slugId;
    }
}
