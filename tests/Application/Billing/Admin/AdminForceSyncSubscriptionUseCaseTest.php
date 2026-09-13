<?php

declare(strict_types=1);

namespace App\Tests\Application\Billing\Admin;

use App\Application\Billing\UseCase\Admin\AdminForceSyncSubscriptionUseCase;
use App\Application\Billing\UseCase\Subscription\SyncSubscriptionUseCase;
use App\Domain\Billing\Entity\Subscription;
use App\Domain\Billing\Enum\SubscriptionStatus;
use App\Domain\Workspace\Entity\Workspace;
use App\Infrastructure\Service\Payment\Stripe\StripeService;
use App\Tests\Application\ReflectionHelperTrait;
use PHPUnit\Framework\TestCase;

final class AdminForceSyncSubscriptionUseCaseTest extends TestCase
{
    use ReflectionHelperTrait;

    public function testFetchesFromStripeAndReplaysSync(): void
    {
        $subscription = $this->createEntityState(Subscription::class, [
            'status' => SubscriptionStatus::ACTIVE,
            'stripeSubscriptionId' => 'sub_123',
        ]);
        $workspace = $this->createEntityState(Workspace::class, ['subscription' => $subscription]);

        $stripeSubscription = $this->createStub(\Stripe\Subscription::class);

        $stripeService = $this->createMock(StripeService::class);
        $stripeService->expects($this->once())
            ->method('getSubscription')->with('sub_123')->willReturn($stripeSubscription);

        $syncUseCase = $this->createMock(SyncSubscriptionUseCase::class);
        $syncUseCase->expects($this->once())->method('__invoke')->with($this->identicalTo($stripeSubscription));

        $useCase = new AdminForceSyncSubscriptionUseCase($stripeService, $syncUseCase);

        $useCase($workspace);
    }

    public function testThrowsWhenNoActiveSubscription(): void
    {
        $workspace = $this->createEntityState(Workspace::class, ['subscription' => null]);

        $stripeService = $this->createMock(StripeService::class);
        $stripeService->expects($this->never())->method('getSubscription');

        $syncUseCase = $this->createMock(SyncSubscriptionUseCase::class);
        $syncUseCase->expects($this->never())->method('__invoke');

        $useCase = new AdminForceSyncSubscriptionUseCase($stripeService, $syncUseCase);

        $this->expectException(\DomainException::class);
        $useCase($workspace);
    }
}
