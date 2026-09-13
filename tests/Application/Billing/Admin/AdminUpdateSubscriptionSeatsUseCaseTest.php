<?php

declare(strict_types=1);

namespace App\Tests\Application\Billing\Admin;

use App\Application\Billing\UseCase\Admin\AdminUpdateSubscriptionSeatsUseCase;
use App\Domain\Billing\Entity\Subscription;
use App\Domain\Billing\Enum\SubscriptionStatus;
use App\Domain\Billing\Event\AdminSubscriptionSeatsUpdatedEvent;
use App\Domain\Billing\Repository\SubscriptionRepositoryInterface;
use App\Domain\Workspace\Entity\Workspace;
use App\Domain\Workspace\Enum\WorkspaceType;
use App\Domain\Workspace\Service\SeatAvailability;
use App\Infrastructure\Service\Payment\Stripe\StripeService;
use App\Tests\Application\ReflectionHelperTrait;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class AdminUpdateSubscriptionSeatsUseCaseTest extends TestCase
{
    use ReflectionHelperTrait;

    private Subscription $subscription;
    private StripeService&MockObject $stripeService;
    private SeatAvailability&Stub $seatAvailability;
    private EventDispatcherInterface&MockObject $eventDispatcher;
    private AdminUpdateSubscriptionSeatsUseCase $useCase;

    private function workspace(int $usedSeats, int $currentSeats = 5, WorkspaceType $type = WorkspaceType::FIRM): Workspace
    {
        $this->subscription = $this->createEntityState(Subscription::class, [
            'stripeSubscriptionId' => 'sub_123',
            'status' => SubscriptionStatus::ACTIVE,
            'seatsCount' => $currentSeats,
        ]);
        $workspace = $this->createEntityState(Workspace::class, [
            'subscription' => $this->subscription,
            'type' => $type,
        ]);

        $this->seatAvailability = $this->createStub(SeatAvailability::class);
        $this->seatAvailability->method('usedSeats')->willReturn($usedSeats);

        $this->stripeService = $this->createMock(StripeService::class);
        $repo = $this->createStub(SubscriptionRepositoryInterface::class);
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);

        $this->useCase = new AdminUpdateSubscriptionSeatsUseCase(
            $repo,
            $this->seatAvailability,
            $this->stripeService,
            $this->eventDispatcher,
        );

        return $workspace;
    }

    public function testUpdatesSeatsAboveFloorAndDispatchesEvent(): void
    {
        $workspace = $this->workspace(usedSeats: 4, currentSeats: 5);
        $this->stripeService->expects($this->once())->method('updateSubscriptionSeats')->with('sub_123', 8);
        $this->eventDispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(static fn (AdminSubscriptionSeatsUpdatedEvent $e): bool => 5 === $e->previousSeats && 8 === $e->newSeats))
            ->willReturnArgument(0);

        ($this->useCase)($workspace, 8, 'admin@kysure.fr', 'Admin KYSURE');

        self::assertSame(8, $this->subscription->seatsCount);
    }

    /**
     * Régression : un admin qui tape un total inférieur aux sièges déjà occupés
     * ne doit jamais pouvoir réduire la quantité facturée Stripe en dessous de
     * l'usage réel du cabinet (ça « prendrait » les sièges des membres déjà en place).
     */
    public function testRejectsSeatsCountBelowSeatsAlreadyUsed(): void
    {
        $workspace = $this->workspace(usedSeats: 6, currentSeats: 8);
        $this->stripeService->expects($this->never())->method('updateSubscriptionSeats');
        $this->eventDispatcher->expects($this->never())->method('dispatch');

        $this->expectException(\DomainException::class);
        ($this->useCase)($workspace, 4, 'admin@kysure.fr', 'Admin KYSURE');

        self::assertSame(8, $this->subscription->seatsCount);
    }

    public function testRejectsSeatsCountBelowPlanMinimumEvenIfUnused(): void
    {
        $workspace = $this->workspace(usedSeats: 0, currentSeats: 2, type: WorkspaceType::FIRM);
        $this->stripeService->expects($this->never())->method('updateSubscriptionSeats');
        $this->eventDispatcher->expects($this->never())->method('dispatch');

        $this->expectException(\DomainException::class);
        ($this->useCase)($workspace, 1, 'admin@kysure.fr', 'Admin KYSURE');
    }

    public function testIsIdempotentWhenSeatsCountUnchanged(): void
    {
        $workspace = $this->workspace(usedSeats: 3, currentSeats: 5);
        $this->stripeService->expects($this->never())->method('updateSubscriptionSeats');
        $this->eventDispatcher->expects($this->never())->method('dispatch');

        ($this->useCase)($workspace, 5, 'admin@kysure.fr', 'Admin KYSURE');
    }

    public function testThrowsWhenNoActiveSubscription(): void
    {
        $workspace = $this->createEntityState(Workspace::class, ['subscription' => null, 'type' => WorkspaceType::FIRM]);

        $this->seatAvailability = $this->createStub(SeatAvailability::class);
        $repo = $this->createStub(SubscriptionRepositoryInterface::class);
        $this->stripeService = $this->createMock(StripeService::class);
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->useCase = new AdminUpdateSubscriptionSeatsUseCase($repo, $this->seatAvailability, $this->stripeService, $this->eventDispatcher);

        $this->stripeService->expects($this->never())->method('updateSubscriptionSeats');
        $this->eventDispatcher->expects($this->never())->method('dispatch');

        $this->expectException(\DomainException::class);
        ($this->useCase)($workspace, 5, 'admin@kysure.fr', 'Admin KYSURE');
    }
}
