<?php

declare(strict_types=1);

namespace App\Application\Billing\UseCase\Admin;

use App\Application\Billing\UseCase\Subscription\SyncSubscriptionUseCase;
use App\Domain\Billing\Entity\Subscription;
use App\Domain\Workspace\Entity\Workspace;
use App\Infrastructure\Service\Payment\Stripe\StripeService;

/**
 * Rejoue la synchro Stripe → local pour un abonnement, sans attendre le
 * prochain webhook. Utile quand un webhook a été manqué ou retardé.
 */
final readonly class AdminForceSyncSubscriptionUseCase
{
    public function __construct(
        private StripeService $stripeService,
        private SyncSubscriptionUseCase $syncSubscriptionUseCase,
    ) {
    }

    public function __invoke(Workspace $workspace): void
    {
        $subscription = $workspace->subscription;

        if (!$subscription instanceof Subscription || null === $subscription->stripeSubscriptionId) {
            throw new \DomainException('Aucun abonnement Stripe à synchroniser.');
        }

        $stripeSubscription = $this->stripeService->getSubscription($subscription->stripeSubscriptionId);
        ($this->syncSubscriptionUseCase)($stripeSubscription);
    }
}
