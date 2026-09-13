<?php

declare(strict_types=1);

namespace App\Tests\Application\Billing\Admin;

use App\Application\Billing\UseCase\Admin\AdminCancelSubscriptionCancellationUseCase;
use App\Domain\Billing\Entity\Subscription;
use App\Domain\Billing\Enum\SubscriptionStatus;
use App\Domain\Billing\Repository\SubscriptionRepositoryInterface;
use App\Domain\Workspace\Entity\Workspace;
use App\Infrastructure\Service\Payment\Stripe\StripeService;
use App\Tests\Application\ReflectionHelperTrait;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class AdminCancelSubscriptionCancellationUseCaseTest extends TestCase
{
    use ReflectionHelperTrait;

    private Subscription $subscription;
    private StripeService&MockObject $stripeService;
    private AdminCancelSubscriptionCancellationUseCase $useCase;

    private function workspace(bool $cancelAtPeriodEnd = true, bool $withStripeId = true): Workspace
    {
        $this->subscription = $this->createEntityState(Subscription::class, [
            'status' => SubscriptionStatus::ACTIVE,
            'stripeSubscriptionId' => $withStripeId ? 'sub_123' : null,
            'cancelAtPeriodEnd' => $cancelAtPeriodEnd,
            'reason' => $cancelAtPeriodEnd ? 'Demande du client' : null,
        ]);
        $workspace = $this->createEntityState(Workspace::class, ['subscription' => $this->subscription]);

        $this->stripeService = $this->createMock(StripeService::class);
        $repo = $this->createStub(SubscriptionRepositoryInterface::class);
        $dispatcher = $this->createStub(EventDispatcherInterface::class);
        $this->useCase = new AdminCancelSubscriptionCancellationUseCase($repo, $this->stripeService, $dispatcher);

        return $workspace;
    }

    public function testRevokesScheduledCancellation(): void
    {
        $workspace = $this->workspace();
        $this->stripeService->expects($this->once())->method('uncancelSubscription')->with('sub_123');

        ($this->useCase)($workspace, 'admin@kysure.fr', 'Admin KYSURE');

        self::assertFalse($this->subscription->cancelAtPeriodEnd);
    }

    public function testThrowsWhenNoCancellationIsScheduled(): void
    {
        $workspace = $this->workspace(cancelAtPeriodEnd: false);
        $this->stripeService->expects($this->never())->method('uncancelSubscription');

        $this->expectException(\DomainException::class);
        ($this->useCase)($workspace, 'admin@kysure.fr', 'Admin KYSURE');
    }

    public function testThrowsWhenNoActiveSubscription(): void
    {
        $workspace = $this->workspace(withStripeId: false);
        $this->stripeService->expects($this->never())->method('uncancelSubscription');

        $this->expectException(\DomainException::class);
        ($this->useCase)($workspace, 'admin@kysure.fr', 'Admin KYSURE');
    }
}
