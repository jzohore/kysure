<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Compliance\Twig\Components\Onboarding;

use App\Application\Compliance\UseCase\ComplianceFolder\DiscardFlashDraftUseCase;
use App\Domain\Compliance\Entity\IndividualFolder;
use App\Domain\Compliance\Enum\ComplianceFolderStatus;
use App\Domain\Compliance\Enum\FolderCreationMethod;
use App\Domain\Compliance\Exception\ComplianceFolderNotFoundException;
use App\Domain\Compliance\Factory\ComplianceFolderFactory;
use App\Domain\Compliance\Repository\ComplianceFolderRepositoryInterface;
use App\Domain\Compliance\Service\DocumentRequirementEngine;
use App\Domain\Workspace\Entity\Workspace;
use App\Infrastructure\Compliance\Twig\Components\Onboarding\FlashStep1TypeComponent;
use App\Tests\Application\ReflectionHelperTrait;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Uid\Uuid;
use Symfony\UX\LiveComponent\LiveResponder;

/**
 * « Retour au choix » sur la page publique du QR code : aucun utilisateur
 * connecté, la session peut porter un id absent, périmé ou étranger.
 */
final class FlashStep1TypeComponentTest extends TestCase
{
    use ReflectionHelperTrait;

    private const string SESSION_KEY = 'flash_draft_folder_id';

    private ComplianceFolderRepositoryInterface&MockObject $repository;
    private EventDispatcherInterface&MockObject $eventDispatcher;
    private Session $session;
    private Workspace $workspace;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(ComplianceFolderRepositoryInterface::class);
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->session = new Session(new MockArraySessionStorage());
        $this->workspace = $this->createEntityState(Workspace::class, ['slugId' => 'wrk_cabinet_a', 'name' => 'Cabinet A']);
    }

    public function testResetWithoutDraftInSessionOnlyShowsTheStepAgain(): void
    {
        $this->repository->expects($this->never())->method('findById');
        $this->eventDispatcher->expects($this->never())->method('dispatch');

        $component = $this->component();
        $component->onReset();

        self::assertTrue($component->isVisible);
    }

    public function testResetWithAStaleDraftIdClearsTheSession(): void
    {
        $folderId = Uuid::v7()->toRfc4122();
        $this->session->set(self::SESSION_KEY, $folderId);

        $this->repository->expects($this->once())
            ->method('findById')
            ->willThrowException(ComplianceFolderNotFoundException::withId($folderId));
        $this->eventDispatcher->expects($this->never())->method('dispatch');

        $component = $this->component();
        $component->onReset();

        self::assertFalse($this->session->has(self::SESSION_KEY));
        self::assertTrue($component->isVisible);
    }

    public function testResetDiscardsTheFlashDraftWithoutAnyLoggedInUser(): void
    {
        $folderId = Uuid::v7()->toRfc4122();
        $this->session->set(self::SESSION_KEY, $folderId);
        $folder = $this->draft($this->workspace);

        $this->repository->expects($this->once())->method('findById')->willReturn($folder);
        $this->repository->expects($this->once())->method('save')->with($this->identicalTo($folder));
        $this->eventDispatcher->expects($this->once())->method('dispatch')->willReturnArgument(0);

        $component = $this->component();
        $component->onReset();

        self::assertSame(ComplianceFolderStatus::DELETED, $folder->status);
        self::assertFalse($this->session->has(self::SESSION_KEY));
        self::assertTrue($component->isVisible);
    }

    public function testResetKeepsAForeignFolderIntactAndLogsAWarning(): void
    {
        $this->session->set(self::SESSION_KEY, Uuid::v7()->toRfc4122());
        $otherWorkspace = $this->createEntityState(Workspace::class, ['slugId' => 'wrk_cabinet_b', 'name' => 'Cabinet B']);
        $folder = $this->draft($otherWorkspace);

        $this->repository->expects($this->once())->method('findById')->willReturn($folder);
        $this->repository->expects($this->never())->method('save');
        $this->eventDispatcher->expects($this->never())->method('dispatch');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');

        $component = $this->component($logger);
        $component->onReset();

        self::assertSame(ComplianceFolderStatus::DRAFT, $folder->status);
        self::assertFalse($this->session->has(self::SESSION_KEY));
        self::assertTrue($component->isVisible);
    }

    private function component(?LoggerInterface $logger = null): FlashStep1TypeComponent
    {
        $request = new Request();
        $request->setSession($this->session);
        $requestStack = new RequestStack();
        $requestStack->push($request);

        $component = new FlashStep1TypeComponent(
            $requestStack,
            $this->repository,
            $logger ?? new NullLogger(),
            new DiscardFlashDraftUseCase($this->repository, $this->eventDispatcher),
            $this->createStub(ComplianceFolderFactory::class),
            $this->createStub(DocumentRequirementEngine::class),
            new LiveResponder(),
        );
        $component->workspace = $this->workspace;
        $component->isVisible = false;

        return $component;
    }

    private function draft(Workspace $workspace): IndividualFolder
    {
        return $this->createEntityState(IndividualFolder::class, [
            'slugId' => 'comp_fol_flash_1',
            'reference' => 'KYC-FLASH01',
            'workspace' => $workspace,
            'creationMethod' => FolderCreationMethod::FLASH_QR_CODE->value,
            'status' => ComplianceFolderStatus::DRAFT,
            'documents' => new ArrayCollection(),
            'meetingRecordings' => new ArrayCollection(),
        ]);
    }
}
