<?php

declare(strict_types=1);

namespace App\Application\Billing\UseCase\Admin;

use App\Domain\Billing\Entity\Subscription;
use App\Domain\Billing\Event\AdminSubscriptionPausedEvent;
use App\Domain\Billing\Repository\SubscriptionRepositoryInterface;
use App\Domain\Workspace\Entity\Workspace;
use App\Infrastructure\Service\Payment\Stripe\StripeService;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final readonly class AdminPauseSubscriptionUseCase
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
            throw new \DomainException('Aucun abonnement à suspendre.');
        }

        if ($subscription->isPaused()) {
            return;
        }

        $this->stripeService->pauseSubscription($subscription->stripeSubscriptionId);
        $subscription->pause();
        $this->subscriptionRepository->save($subscription);

        $this->eventDispatcher->dispatch(new AdminSubscriptionPausedEvent(
            subscription: $subscription,
            workspace: $workspace,
            actorEmail: $actorEmail,
            actorFullName: $actorFullName,
        ));
    }
}
