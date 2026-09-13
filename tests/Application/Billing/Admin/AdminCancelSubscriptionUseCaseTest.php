<?php

declare(strict_types=1);

namespace App\Tests\Application\Billing\Admin;

use App\Application\Billing\UseCase\Admin\AdminCancelSubscriptionUseCase;
use App\Domain\Billing\Entity\Subscription;
use App\Domain\Billing\Enum\SubscriptionStatus;
use App\Domain\Billing\Repository\SubscriptionRepositoryInterface;
use App\Domain\Workspace\Entity\Workspace;
use App\Infrastructure\Service\Payment\Stripe\StripeService;
use App\Tests\Application\ReflectionHelperTrait;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class AdminCancelSubscriptionUseCaseTest extends TestCase
{
    use ReflectionHelperTrait;

    private Subscription $subscription;
    private StripeService&MockObject $stripeService;
    private AdminCancelSubscriptionUseCase $useCase;

    private function workspace(bool $cancelAtPeriodEnd = false, bool $withStripeId = true): Workspace
    {
        $this->subscription = $this->createEntityState(Subscription::class, [
            'status' => SubscriptionStatus::ACTIVE,
            'stripeSubscriptionId' => $withStripeId ? 'sub_123' : null,
            'cancelAtPeriodEnd' => $cancelAtPeriodEnd,
        ]);
        $workspace = $this->createEntityState(Workspace::class, ['subscription' => $this->subscription]);

        $this->stripeService = $this->createMock(StripeService::class);
        $repo = $this->createStub(SubscriptionRepositoryInterface::class);
        $dispatcher = $this->createStub(EventDispatcherInterface::class);
        $this->useCase = new AdminCancelSubscriptionUseCase($repo, $this->stripeService, $dispatcher);

        return $workspace;
    }

    public function testSchedulesCancellationWithReason(): void
    {
        $workspace = $this->workspace();
        $this->stripeService->expects($this->once())->method('cancelSubscription')->with('sub_123', 'Demande du client');

        ($this->useCase)($workspace, '  Demande du client  ', 'admin@kysure.fr', 'Admin KYSURE');

        self::assertTrue($this->subscription->cancelAtPeriodEnd);
    }

    public function testRejectsBlankReason(): void
    {
        $workspace = $this->workspace();
        $this->stripeService->expects($this->never())->method('cancelSubscription');

        $this->expectException(\DomainException::class);
        ($this->useCase)($workspace, '   ', 'admin@kysure.fr', 'Admin KYSURE');
    }

    public function testIsIdempotentWhenAlreadyPending(): void
    {
        $workspace = $this->workspace(cancelAtPeriodEnd: true);
        $this->stripeService->expects($this->never())->method('cancelSubscription');

        ($this->useCase)($workspace, 'Motif', 'admin@kysure.fr', 'Admin KYSURE');
    }

    public function testThrowsWhenNoActiveSubscription(): void
    {
        $workspace = $this->workspace(withStripeId: false);
        $this->stripeService->expects($this->never())->method('cancelSubscription');

        $this->expectException(\DomainException::class);
        ($this->useCase)($workspace, 'Motif', 'admin@kysure.fr', 'Admin KYSURE');
    }
}
