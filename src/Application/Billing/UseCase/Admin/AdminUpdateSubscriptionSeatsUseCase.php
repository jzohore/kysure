<?php

declare(strict_types=1);

namespace App\Application\Billing\UseCase\Admin;

use App\Domain\Billing\Entity\Subscription;
use App\Domain\Billing\Enum\Plan;
use App\Domain\Billing\Event\AdminSubscriptionSeatsUpdatedEvent;
use App\Domain\Billing\Repository\SubscriptionRepositoryInterface;
use App\Domain\Workspace\Entity\Workspace;
use App\Domain\Workspace\Enum\WorkspaceType;
use App\Domain\Workspace\Service\SeatAvailability;
use App\Infrastructure\Service\Payment\Stripe\StripeService;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Ajuste le nombre de sièges facturés d'un cabinet depuis le back-office
 * (support/geste commercial). Même appel Stripe et même garde-fou que le flux
 * self-service ({@see \App\Application\Billing\UseCase\Subscription\UpdateSubscriptionSeatsUseCase}) :
 * on ne peut jamais descendre sous le nombre de sièges déjà occupés (membres +
 * invitations en attente), sans quoi Stripe réduirait la quantité facturée en
 * dessous de l'usage réel du cabinet.
 */
final readonly class AdminUpdateSubscriptionSeatsUseCase
{
    public function __construct(
        private SubscriptionRepositoryInterface $subscriptionRepository,
        private SeatAvailability $seatAvailability,
        private StripeService $stripeService,
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function __invoke(Workspace $workspace, int $desiredSeats, string $actorEmail, string $actorFullName): void
    {
        $subscription = $workspace->subscription;

        if (!$subscription instanceof Subscription || null === $subscription->stripeSubscriptionId) {
            throw new \DomainException('Aucun abonnement actif : impossible d\'ajuster les sièges.');
        }

        $minPlanSeats = Plan::forWorkspaceType($workspace->isFirm() ? WorkspaceType::FIRM : WorkspaceType::INDIVIDUAL)->getMinSeats();
        $floor = max($minPlanSeats, $this->seatAvailability->usedSeats($workspace));

        if ($desiredSeats < $floor) {
            throw new \DomainException(sprintf('Impossible de descendre en dessous de %d sièges (minimum de l\'offre ou sièges déjà occupés).', $floor));
        }

        if ($desiredSeats === $subscription->seatsCount) {
            return;
        }

        $previousSeats = $subscription->seatsCount;

        $this->stripeService->updateSubscriptionSeats($subscription->stripeSubscriptionId, $desiredSeats);
        $subscription->updateSeats($desiredSeats);
        $this->subscriptionRepository->save($subscription);

        $this->eventDispatcher->dispatch(new AdminSubscriptionSeatsUpdatedEvent(
            subscription: $subscription,
            workspace: $workspace,
            previousSeats: $previousSeats,
            newSeats: $desiredSeats,
            actorEmail: $actorEmail,
            actorFullName: $actorFullName,
        ));
    }
}
