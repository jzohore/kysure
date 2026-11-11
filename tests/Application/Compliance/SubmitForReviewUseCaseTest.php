<?php

declare(strict_types=1);

namespace App\Tests\Application\Compliance;

use App\Application\Compliance\UseCase\ComplianceFolder\SubmitForReviewUseCase;
use App\Domain\Compliance\Entity\ComplianceFolder;
use App\Domain\Compliance\Enum\ComplianceFolderStatus;
use App\Domain\Compliance\Event\ComplianceFolderSubmittedEvent;
use App\Domain\Compliance\Repository\ComplianceFolderRepositoryInterface;
use App\Domain\User\Entity\User;
use App\Domain\Workspace\Repository\WorkspaceMemberRepositoryInterface;
use App\Domain\Workspace\Service\CurrentUserProvider;
use App\Infrastructure\Compliance\Listener\NotifyWorkspaceOfFolderSubmittedListener;
use App\Infrastructure\Compliance\Message\DispatchNotifyFolderSubmitted;
use App\Tests\Domain\AdequacyReport\AdequacyReportFixturesTrait;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Uuid;

final class SubmitForReviewUseCaseTest extends TestCase
{
    use AdequacyReportFixturesTrait;

    protected function setUp(): void
    {
        $this->setUpAdequacyFixtures();
        new \ReflectionProperty(ComplianceFolder::class, 'status')->setValue($this->folder, ComplianceFolderStatus::AWAITING_CLIENT);
        new \ReflectionProperty(ComplianceFolder::class, 'history')->setValue($this->folder, []);
    }

    public function testClientSubmissionPutsTheFolderInReviewAndTellsWhoSubmitted(): void
    {
        $userProvider = $this->createStub(CurrentUserProvider::class);
        $userProvider->method('isAuthenticated')->willReturn(false);

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::once())->method('dispatch')->with(self::callback(fn (ComplianceFolderSubmittedEvent $event): bool => $this->folder->slugId === $event->folderSlugId
            && ComplianceFolderSubmittedEvent::ACTOR_CLIENT === $event->actorType
            && null === $event->actorName))->willReturnArgument(0);

        new SubmitForReviewUseCase($this->createStub(ComplianceFolderRepositoryInterface::class), $userProvider, $dispatcher)($this->folder);

        self::assertSame(ComplianceFolderStatus::IN_REVIEW, $this->folder->status);
    }

    public function testCabinetAdminsAreNotifiedWhenTheClientSubmits(): void
    {
        $admin = $this->createEntityState(User::class, ['id' => Uuid::v7(), 'email' => 'admin@cabinet.fr', 'firstName' => 'A', 'lastName' => 'B']);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())->method('dispatch')->with(self::callback(static fn (DispatchNotifyFolderSubmitted $message): bool => 'admin@cabinet.fr' === $message->recipientEmail && 'KYC-2026-001' === $message->folderReference))
            ->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));

        $this->listener($bus, [$admin])(new ComplianceFolderSubmittedEvent($this->folder->slugId, ComplianceFolderSubmittedEvent::ACTOR_CLIENT, null));
    }

    public function testSubmissionByTheCgpNotifiesNobody(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $this->listener($bus, [])(new ComplianceFolderSubmittedEvent($this->folder->slugId, ComplianceFolderSubmittedEvent::ACTOR_CGP, 'Marie CURIE'));
    }

    /**
     * @param list<User> $admins
     */
    private function listener(MessageBusInterface $bus, array $admins): NotifyWorkspaceOfFolderSubmittedListener
    {
        $folderRepo = $this->createStub(ComplianceFolderRepositoryInterface::class);
        $folderRepo->method('findOneBySlugId')->willReturn($this->folder);

        $memberRepo = $this->createStub(WorkspaceMemberRepositoryInterface::class);
        $memberRepo->method('findMembersAdmin')->willReturn($admins);

        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('https://app.kysure.test/app/compliance/fld_1');

        return new NotifyWorkspaceOfFolderSubmittedListener($folderRepo, $memberRepo, $bus, $urlGenerator, new NullLogger());
    }
}
