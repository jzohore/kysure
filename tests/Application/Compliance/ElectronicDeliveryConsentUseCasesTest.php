<?php

declare(strict_types=1);

namespace App\Tests\Application\Compliance;

use App\Application\Compliance\UseCase\ElectronicDelivery\ElectronicDeliveryConsentResolver;
use App\Application\Compliance\UseCase\ElectronicDelivery\GiveElectronicDeliveryConsentUseCase;
use App\Application\Compliance\UseCase\ElectronicDelivery\RequestElectronicDeliveryConsentUseCase;
use App\Domain\Compliance\Entity\ComplianceDocument;
use App\Domain\Compliance\Entity\DerAcknowledgement;
use App\Domain\Compliance\Entity\ElectronicDeliveryConsent;
use App\Domain\Compliance\Event\ElectronicDeliveryConsentGivenEvent;
use App\Domain\Compliance\Event\ElectronicDeliveryConsentRequestedEvent;
use App\Domain\Compliance\Repository\ComplianceDocumentRepositoryInterface;
use App\Domain\Compliance\Repository\ComplianceFolderRepositoryInterface;
use App\Domain\Compliance\Repository\ElectronicDeliveryConsentRepositoryInterface;
use App\Domain\Compliance\ValueObject\DerStatement;
use App\Domain\Compliance\ValueObject\ElectronicDeliveryConsentStatement;
use App\Domain\Database\TransactionManagerInterface;
use App\Domain\User\Entity\Client;
use App\Domain\Workspace\Service\CurrentUserProvider;
use App\Tests\Domain\AdequacyReport\AdequacyReportFixturesTrait;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Avenant au DER : un client qui a accusé un ancien DER donne son accord pour la remise
 * électronique sans que le DER figé ni le statut du dossier ne bougent.
 */
final class ElectronicDeliveryConsentUseCasesTest extends TestCase
{
    use AdequacyReportFixturesTrait;

    protected function setUp(): void
    {
        $this->setUpAdequacyFixtures();
    }

    public function testCgpRequestsTheAmendmentForAClientWhoAcknowledgedAnOlderDer(): void
    {
        $statusBefore = $this->folder->status;
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::once())->method('dispatch')->with(self::isInstanceOf(ElectronicDeliveryConsentRequestedEvent::class))->willReturnArgument(0);

        $this->requestUseCase('2026-09-01', null, $dispatcher)($this->folder);

        self::assertNotNull($this->folder->electronicDeliveryConsentRequestedAt);
        self::assertSame($statusBefore, $this->folder->status, 'Le statut du dossier ne bouge pas.');
    }

    public function testRequestIsNotResentWithin24Hours(): void
    {
        $this->requestUseCase('2026-09-01', null)($this->folder);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/24 h/');

        $this->requestUseCase('2026-09-01', null)($this->folder);
    }

    public function testNothingToRequestWhenTheDerAlreadyCarriesTheConsent(): void
    {
        $this->expectException(\DomainException::class);

        $this->requestUseCase(DerStatement::VERSION, null)($this->folder);
    }

    public function testClientGivesTheConsentOnceAndOnlyOnce(): void
    {
        $consentRepo = $this->createMock(ElectronicDeliveryConsentRepositoryInterface::class);
        $saved = null;
        $consentRepo->expects(self::once())->method('save')->willReturnCallback(static function (ElectronicDeliveryConsent $consent) use (&$saved): void {
            $saved = $consent;
        });
        $consentRepo->method('findLatestByFolder')->willReturnCallback(static function () use (&$saved): ?ElectronicDeliveryConsent {
            return $saved;
        });

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::once())->method('dispatch')->with(self::isInstanceOf(ElectronicDeliveryConsentGivenEvent::class))->willReturnArgument(0);

        $useCase = $this->giveUseCase($consentRepo, $dispatcher);
        $useCase($this->client, $this->folder->slugId);
        // Double envoi du formulaire : rien de plus.
        $useCase($this->client, $this->folder->slugId);

        self::assertInstanceOf(ElectronicDeliveryConsent::class, $saved);
        self::assertSame(ElectronicDeliveryConsentStatement::TEXT, $saved->statementText);
        self::assertSame(ElectronicDeliveryConsentStatement::VERSION, $saved->statementVersion);
    }

    public function testAClientCannotConsentOnSomeoneElsesFolder(): void
    {
        $intruder = $this->createEntityState(Client::class, ['id' => Uuid::v7(), 'slugId' => 'cli_2', 'email' => 'autre@example.com']);

        $this->expectException(\DomainException::class);

        ElectronicDeliveryConsent::give($this->folder, $intruder, ElectronicDeliveryConsentStatement::current());
    }

    private function resolver(string $derVersion, ?ElectronicDeliveryConsentRepositoryInterface $consentRepo = null): ElectronicDeliveryConsentResolver
    {
        $acknowledgement = $this->createEntityState(DerAcknowledgement::class, [
            'slugId' => 'der_ack_1',
            'acknowledgedAt' => new \DateTimeImmutable('2026-09-17'),
            'statementVersion' => $derVersion,
            'revokedAt' => null,
        ]);
        $documentRepo = $this->createStub(ComplianceDocumentRepositoryInterface::class);
        $documentRepo->method('findDerByFolder')->willReturn($this->createEntityState(ComplianceDocument::class, ['acknowledgements' => new ArrayCollection([$acknowledgement])]));

        if (!$consentRepo instanceof ElectronicDeliveryConsentRepositoryInterface) {
            $consentRepo = $this->createStub(ElectronicDeliveryConsentRepositoryInterface::class);
            $consentRepo->method('findLatestByFolder')->willReturn(null);
        }

        return new ElectronicDeliveryConsentResolver($documentRepo, $consentRepo);
    }

    private function requestUseCase(string $derVersion, ?ElectronicDeliveryConsentRepositoryInterface $consentRepo, ?EventDispatcherInterface $dispatcher = null): RequestElectronicDeliveryConsentUseCase
    {
        $userProvider = $this->createStub(CurrentUserProvider::class);
        $userProvider->method('getUser')->willReturn($this->cgp);

        return new RequestElectronicDeliveryConsentUseCase(
            $this->resolver($derVersion, $consentRepo),
            $this->createStub(ComplianceFolderRepositoryInterface::class),
            $userProvider,
            $dispatcher ?? $this->createStub(EventDispatcherInterface::class),
        );
    }

    private function giveUseCase(ElectronicDeliveryConsentRepositoryInterface $consentRepo, EventDispatcherInterface $dispatcher): GiveElectronicDeliveryConsentUseCase
    {
        $folderRepo = $this->createStub(ComplianceFolderRepositoryInterface::class);
        $folderRepo->method('findOneBySlugIdAndClient')->willReturn($this->folder);

        $transactionManager = $this->createStub(TransactionManagerInterface::class);
        $transactionManager->method('transactional')->willReturnCallback(static fn (callable $cb): mixed => $cb());

        return new GiveElectronicDeliveryConsentUseCase(
            $folderRepo,
            $consentRepo,
            $this->resolver('2026-09-01', $consentRepo),
            $transactionManager,
            $dispatcher,
        );
    }
}
