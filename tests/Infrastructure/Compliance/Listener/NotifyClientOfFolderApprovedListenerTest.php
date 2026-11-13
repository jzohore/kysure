<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Compliance\Listener;

use App\Domain\Compliance\Entity\ComplianceFolder;
use App\Domain\Compliance\Event\ComplianceFolderApprovedEvent;
use App\Domain\Compliance\Repository\ComplianceFolderRepositoryInterface;
use App\Domain\Workspace\Entity\Workspace;
use App\Infrastructure\Compliance\Listener\NotifyClientOfFolderApprovedListener;
use App\Infrastructure\Compliance\Message\SendFolderApprovedToClientMessage;
use App\Tests\Domain\AdequacyReport\AdequacyReportFixturesTrait;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class NotifyClientOfFolderApprovedListenerTest extends TestCase
{
    use AdequacyReportFixturesTrait;

    protected function setUp(): void
    {
        $this->setUpAdequacyFixtures();
        new \ReflectionProperty(Workspace::class, 'email')->setValue($this->workspace, 'cabinet@example.fr');
    }

    public function testClientIsToldTheFolderIsValidatedWithoutTheRiskLevel(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())->method('dispatch')->with(self::callback(static function (SendFolderApprovedToClientMessage $message): bool {
            self::assertSame('jean@example.com', $message->clientEmail);
            self::assertSame('KYC-2026-001', $message->folderReference);
            self::assertStringNotContainsString('HIGH', serialize($message), 'Le niveau de risque LCB-FT reste interne au cabinet.');

            return true;
        }))->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));

        $this->listener($bus, $this->folder)(new ComplianceFolderApprovedEvent($this->folder->slugId, 'HIGH', 'Marie CURIE'));
    }

    public function testFolderWithoutClientSendsNothing(): void
    {
        new \ReflectionProperty(ComplianceFolder::class, 'client')->setValue($this->folder, null);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $this->listener($bus, $this->folder)(new ComplianceFolderApprovedEvent($this->folder->slugId, 'LOW', 'Marie CURIE'));
    }

    private function listener(MessageBusInterface $bus, ComplianceFolder $folder): NotifyClientOfFolderApprovedListener
    {
        $folderRepo = $this->createStub(ComplianceFolderRepositoryInterface::class);
        $folderRepo->method('findOneBySlugId')->willReturn($folder);

        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('https://app.kysure.test/portal/folders/fld_1');

        return new NotifyClientOfFolderApprovedListener($folderRepo, $bus, $urlGenerator);
    }
}
