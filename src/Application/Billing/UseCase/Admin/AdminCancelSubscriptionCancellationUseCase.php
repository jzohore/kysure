<?php

declare(strict_types=1);

namespace App\Application\Billing\UseCase\Admin;

use App\Domain\Billing\Entity\Subscription;
use App\Domain\Billing\Event\AdminSubscriptionCancellationRevokedEvent;
use App\Domain\Billing\Repository\SubscriptionRepositoryInterface;
use App\Domain\Workspace\Entity\Workspace;
use App\Infrastructure\Service\Payment\Stripe\StripeService;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Annule une résiliation programmée, depuis le back-office (ex. le client
 * rappelle le support pour rester). Ne redonne pas l'offre de fidélité
 * (remise) : {@see Subscription::cancelScheduledCancellation()} se contente
 * de lever le drapeau, sans les effets de bord de
 * {@see Subscription::claimRetentionOffer()}.
 */
final readonly class AdminCancelSubscriptionCancellationUseCase
{
    public function __construct(
        private SubscriptionRepositoryInterface $subscriptionRepository,
        private StripeService $stripeService,
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function __invoke(Workspace $workspace, string $actorEmail, string $actorFullName): void
    {
        $subscription = $workspace->subscription;

        if (!$subscription instanceof Subscription || null === $subscription->stripeSubscriptionId) {
            throw new \DomainException('Aucun abonnement actif.');
        }

        if (!$subscription->cancelAtPeriodEnd) {
            throw new \DomainException('Aucune résiliation n\'est programmée pour cet abonnement.');
        }

        $this->stripeService->uncancelSubscription($subscription->stripeSubscriptionId);
        $subscription->cancelScheduledCancellation();
        $this->subscriptionRepository->save($subscription);

        $this->eventDispatcher->dispatch(new AdminSubscriptionCancellationRevokedEvent(
            subscription: $subscription,
            workspace: $workspace,
            actorEmail: $actorEmail,
            actorFullName: $actorFullName,
        ));
    }
}
