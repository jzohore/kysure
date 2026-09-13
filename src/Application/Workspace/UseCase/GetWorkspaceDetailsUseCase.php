<?php

declare(strict_types=1);

namespace App\Application\Workspace\UseCase;

use App\Application\Workspace\DTO\Response\WorkspaceDetailsDto;
use App\Domain\Billing\Entity\Subscription;
use App\Domain\Workspace\Exception\WorkspaceNotFoundException;
use App\Domain\Workspace\Repository\WorkspaceRepositoryInterface;
use App\Infrastructure\Service\Payment\Stripe\StripeService;

final readonly class GetWorkspaceDetailsUseCase
{
    public function __construct(
        private WorkspaceRepositoryInterface $workspaceRepository,
        private StripeService $stripeService,
    ) {
    }

    public function __invoke(string $slugId): WorkspaceDetailsDto
    {
        // On récupère le workspace. Le repository doit gérer l'optimisation des requêtes
        // (par exemple, faire des left joins sur les collections si on ne veut pas de lazy loading,
        // ou s'assurer que les collections sont en EXTRA_LAZY pour les count()).
        $workspace = $this->workspaceRepository->findOneBySlug($slugId);

        if (!$workspace instanceof \App\Domain\Workspace\Entity\Workspace) {
            throw WorkspaceNotFoundException::withSlug($slugId);
        }

        return WorkspaceDetailsDto::fromEntity($workspace, $this->resolveSubscriptionBasePrice($workspace->subscription));
    }

    /** Prix par siège lu en direct sur Stripe (même stratégie que la page abonnement du cabinet). */
    private function resolveSubscriptionBasePrice(?Subscription $subscription): ?float
    {
        if (!$subscription instanceof Subscription || null === $subscription->stripeSubscriptionId) {
            return null;
        }

        $remoteSubscription = $this->stripeService->getSubscription($subscription->stripeSubscriptionId);
        $firstItem = $remoteSubscription->items->data[0] ?? null;

        return ($firstItem?->plan->amount ?? 0) / 100;
    }
}
