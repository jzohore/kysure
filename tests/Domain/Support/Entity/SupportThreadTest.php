<?php

declare(strict_types=1);

namespace App\Tests\Domain\Support\Entity;

use App\Domain\Support\Entity\SupportMessage;
use App\Domain\Support\Entity\SupportThread;
use App\Domain\Support\Enum\SupportPriority;
use App\Domain\Support\Enum\SupportSenderType;
use App\Domain\Support\Enum\SupportThreadStatus;
use App\Domain\User\Entity\User;
use App\Domain\Workspace\Entity\Workspace;
use App\Tests\Application\ReflectionHelperTrait;
use PHPUnit\Framework\TestCase;

final class SupportThreadTest extends TestCase
{
    use ReflectionHelperTrait;

    public function testOpenInitializesTicketWithNormalPriorityAndTwoHourDueDate(): void
    {
        $thread = SupportThread::open(
            $this->createStub(Workspace::class),
            $this->createStub(User::class),
            'category',
            'topic',
        );

        self::assertSame(SupportThreadStatus::OPEN, $thread->status);
        self::assertSame(SupportPriority::NORMAL, $thread->priority);
        self::assertCount(0, $thread->messages);
        self::assertEquals($thread->createdAt->add(new \DateInterval('PT2H')), $thread->dueAt);
    }

    public function testResolveThenReopenTogglesStatus(): void
    {
        $thread = $this->createEntityState(SupportThread::class, [
            'status' => SupportThreadStatus::OPEN,
        ]);

        $thread->resolve();
        self::assertSame(SupportThreadStatus::RESOLVED, $thread->status);

        $thread->reopen();
        self::assertSame(SupportThreadStatus::OPEN, $thread->status);
    }

    public function testMarkAsReadByClientOnlyMarksUnreadAdminMessages(): void
    {
        $thread = SupportThread::open(
            $this->createStub(Workspace::class),
            $this->createStub(User::class),
            'category',
            'topic',
        );

        $clientMessage = SupportMessage::write($thread, SupportSenderType::CLIENT, 'Bonjour');
        $adminMessage = SupportMessage::write($thread, SupportSenderType::ADMIN, 'Bonjour, comment vous aider ?');

        $thread->markAsReadByClient();

        self::assertNull($clientMessage->readAt);
        self::assertNotNull($adminMessage->readAt);
    }

    public function testChangePriorityRecomputesDueDateFromCreationDate(): void
    {
        $thread = SupportThread::open(
            $this->createStub(Workspace::class),
            $this->createStub(User::class),
            'category',
            'topic',
        );
        $normalDueAt = $thread->dueAt;

        $thread->changePriority(SupportPriority::URGENT);

        self::assertNotEquals($normalDueAt, $thread->dueAt);
        self::assertEquals($thread->createdAt->add(new \DateInterval('PT30M')), $thread->dueAt);
    }

    public function testOpenThreadPastItsDueDateIsOverdue(): void
    {
        $thread = $this->createEntityState(SupportThread::class, [
            'status' => SupportThreadStatus::OPEN,
            'dueAt' => new \DateTimeImmutable('-1 minute'),
        ]);

        self::assertTrue($thread->isOverdue());
    }

    public function testOpenThreadBeforeItsDueDateIsNotOverdue(): void
    {
        $thread = $this->createEntityState(SupportThread::class, [
            'status' => SupportThreadStatus::OPEN,
            'dueAt' => new \DateTimeImmutable('+1 hour'),
        ]);

        self::assertFalse($thread->isOverdue());
    }

    public function testResolvedThreadIsNeverOverdueEvenPastItsDueDate(): void
    {
        $thread = $this->createEntityState(SupportThread::class, [
            'status' => SupportThreadStatus::RESOLVED,
            'dueAt' => new \DateTimeImmutable('-1 minute'),
        ]);

        self::assertFalse($thread->isOverdue());
    }
}
