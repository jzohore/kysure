<?php

declare(strict_types=1);

namespace App\Tests\Application\Billing\Admin;

use App\Application\Billing\UseCase\Admin\AdminPauseSubscriptionUseCase;
use App\Application\Billing\UseCase\Admin\AdminResumeSubscriptionUseCase;
use App\Domain\Billing\Entity\Subscription;
use App\Domain\Billing\Enum\SubscriptionStatus;
use App\Domain\Billing\Repository\SubscriptionRepositoryInterface;
use App\Domain\Workspace\Entity\Workspace;
use App\Infrastructure\Service\Payment\Stripe\StripeService;
use App\Tests\Application\ReflectionHelperTrait;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class AdminPauseResumeSubscriptionUseCaseTest extends TestCase
{
    use ReflectionHelperTrait;

    private Subscription $subscription;
    private StripeService&MockObject $stripeService;

    /**
     * @return array{AdminPauseSubscriptionUseCase, AdminResumeSubscriptionUseCase, Workspace}
     */
    private function useCases(bool $paused = false, bool $withStripeId = true): array
    {
        $this->subscription = $this->createEntityState(Subscription::class, [
            'status' => SubscriptionStatus::ACTIVE,
            'stripeSubscriptionId' => $withStripeId ? 'sub_123' : null,
            'pausedAt' => $paused ? new \DateTimeImmutable() : null,
        ]);
        $workspace = $this->createEntityState(Workspace::class, ['subscription' => $this->subscription]);

        $this->stripeService = $this->createMock(StripeService::class);
        $repo = $this->createStub(SubscriptionRepositoryInterface::class);
        $dispatcher = $this->createStub(EventDispatcherInterface::class);

        return [
            new AdminPauseSubscriptionUseCase($repo, $this->stripeService, $dispatcher),
            new AdminResumeSubscriptionUseCase($repo, $this->stripeService, $dispatcher),
            $workspace,
        ];
    }

    public function testPauseCallsStripeAndFreezes(): void
    {
        [$pause, , $workspace] = $this->useCases();
        $this->stripeService->expects($this->once())->method('pauseSubscription')->with('sub_123');

        $pause($workspace, 'admin@kysure.fr', 'Admin KYSURE');

        self::assertTrue($this->subscription->isPaused());
    }

    public function testPauseIsIdempotentWhenAlreadyPaused(): void
    {
        [$pause, , $workspace] = $this->useCases(paused: true);
        $this->stripeService->expects($this->never())->method('pauseSubscription');

        $pause($workspace, 'admin@kysure.fr', 'Admin KYSURE');
    }

    public function testPauseRejectsWorkspaceWithoutActiveSubscription(): void
    {
        [$pause, , $workspace] = $this->useCases(withStripeId: false);
        $this->stripeService->expects($this->never())->method('pauseSubscription');

        $this->expectException(\DomainException::class);
        $pause($workspace, 'admin@kysure.fr', 'Admin KYSURE');
    }

    public function testResumeCallsStripeAndUnfreezes(): void
    {
        [, $resume, $workspace] = $this->useCases(paused: true);
        $this->stripeService->expects($this->once())->method('resumeSubscription')->with('sub_123');

        $resume($workspace, 'admin@kysure.fr', 'Admin KYSURE');

        self::assertFalse($this->subscription->isPaused());
    }

    public function testResumeIsIdempotentWhenNotPaused(): void
    {
        [, $resume, $workspace] = $this->useCases(paused: false);
        $this->stripeService->expects($this->never())->method('resumeSubscription');

        $resume($workspace, 'admin@kysure.fr', 'Admin KYSURE');
    }
}
