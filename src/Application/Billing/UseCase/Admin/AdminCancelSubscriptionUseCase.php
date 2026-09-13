<?php

declare(strict_types=1);

namespace App\Application\Billing\UseCase\Admin;

use App\Domain\Billing\Entity\Subscription;
use App\Domain\Billing\Event\AdminSubscriptionCanceledEvent;
use App\Domain\Billing\Repository\SubscriptionRepositoryInterface;
use App\Domain\Workspace\Entity\Workspace;
use App\Infrastructure\Service\Payment\Stripe\StripeService;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Programme la résiliation d'un abonnement en fin de période, depuis le
 * back-office. Contrairement au flux self-service actuel (qui ne mute que
 * l'état local et laisse un listener notifier Stripe), on appelle
 * directement {@see StripeService::cancelSubscription()} ici : plus simple à
 * auditer pour une action déclenchée manuellement par le support.
 */
final readonly class AdminCancelSubscriptionUseCase
{
    public function __construct(
        private SubscriptionRepositoryInterface $subscriptionRepository,
        private StripeService $stripeService,
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function __invoke(Workspace $workspace, string $reason, string $actorEmail, string $actorFullName): void
    {
        $subscription = $workspace->subscription;

        if (!$subscription instanceof Subscription || null === $subscription->stripeSubscriptionId) {
            throw new \DomainException('Aucun abonnement à résilier.');
        }

        $reason = trim($reason);
        if ('' === $reason) {
            throw new \DomainException('Un motif est obligatoire pour programmer une résiliation.');
        }

        if ($subscription->cancelAtPeriodEnd) {
            return;
        }

        $this->stripeService->cancelSubscription($subscription->stripeSubscriptionId, $reason);
        $subscription->markAsPendingCancellation($reason);
        $this->subscriptionRepository->save($subscription);

        $this->eventDispatcher->dispatch(new AdminSubscriptionCanceledEvent(
            subscription: $subscription,
            workspace: $workspace,
            reason: $reason,
            actorEmail: $actorEmail,
            actorFullName: $actorFullName,
        ));
    }
}
