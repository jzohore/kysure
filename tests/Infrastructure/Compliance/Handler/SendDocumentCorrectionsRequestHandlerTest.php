<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Compliance\Handler;

use App\Application\Compliance\DTO\Response\ComplianceFolderShowResponse;
use App\Application\Compliance\UseCase\ComplianceFolder\ComplianceFolderShowAssembler;
use App\Domain\Compliance\Entity\ComplianceDocument;
use App\Domain\Compliance\Entity\IndividualFolder;
use App\Domain\Compliance\Enum\ComplianceFolderStatus;
use App\Domain\Compliance\Enum\DocumentRejectionReason;
use App\Domain\Compliance\Enum\DocumentType;
use App\Domain\Compliance\Repository\ComplianceFolderRepositoryInterface;
use App\Domain\Kyc\Enum\DocumentStatus;
use App\Infrastructure\Compliance\Handler\SendDocumentCorrectionsRequestHandler;
use App\Infrastructure\Compliance\Message\SendDocumentCorrectionsRequestMessage;
use App\Infrastructure\Notification\Email\Compliance\DocumentCorrectionsRequestClientEmail;
use App\Tests\Application\ReflectionHelperTrait;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Uid\Uuid;

final class SendDocumentCorrectionsRequestHandlerTest extends TestCase
{
    use ReflectionHelperTrait;

    private IndividualFolder $folder;

    protected function setUp(): void
    {
        $this->folder = $this->createEntityState(IndividualFolder::class, [
            'id' => Uuid::v7(),
            'slugId' => 'comp_fol_1',
            'reference' => 'DOS-1',
            'status' => ComplianceFolderStatus::IN_REVIEW,
            'history' => [],
            'documents' => new ArrayCollection(),
        ]);
    }

    private function document(DocumentType $type): ComplianceDocument
    {
        $document = $this->createEntityState(ComplianceDocument::class, [
            'id' => Uuid::v7(),
            'slugId' => 'comp_doc_' . $type->value,
            'folder' => $this->folder,
            'type' => $type,
            'status' => DocumentStatus::UPLOADED,
            'storagePath' => 'kyc/doc.pdf',
            'isMandatory' => true,
            'acknowledgements' => new ArrayCollection(),
        ]);
        $this->folder->documents->add($document);

        return $document;
    }

    private function handler(MailerInterface $mailer, ?string $contactEmail = 'client@example.test'): SendDocumentCorrectionsRequestHandler
    {
        $folders = $this->createStub(ComplianceFolderRepositoryInterface::class);
        $folders->method('findById')->willReturn($this->folder);
        $assembler = $this->createStub(ComplianceFolderShowAssembler::class);
        $assembler->method('assemble')->willReturn($this->createEntityState(ComplianceFolderShowResponse::class, [
            'contactEmail' => $contactEmail,
            'contactName' => 'Paul Alois',
            'workspaceName' => 'Cabinet Exemple',
        ]));

        return new SendDocumentCorrectionsRequestHandler($folders, $assembler, $mailer, new NullLogger());
    }

    /**
     * @param list<ComplianceDocument> $documents
     */
    private function message(array $documents): SendDocumentCorrectionsRequestMessage
    {
        return new SendDocumentCorrectionsRequestMessage(
            folderId: (string) $this->folder->id,
            documentIds: array_map(static fn (ComplianceDocument $doc): string => (string) $doc->id, $documents),
            portalUrl: 'https://kysure.test/portail/dossier',
        );
    }

    public function testOneEmailListsEveryPieceOfTheRequestWithItsReason(): void
    {
        $idCard = $this->document(DocumentType::ID_CARD);
        $proof = $this->document(DocumentType::PROOF_OF_ADDRESS);
        $idCard->rejectByAdvisor(DocumentRejectionReason::EXPIRED, null, 'Conseiller');
        $proof->rejectByAdvisor(DocumentRejectionReason::TOO_OLD, null, 'Conseiller');
        $this->folder->requestCorrectionsFromClient('Conseiller');

        $sent = null;
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects($this->once())->method('send')->willReturnCallback(static function (object $email) use (&$sent): void {
            $sent = $email;
        });

        $this->handler($mailer)($this->message([$idCard, $proof]));

        self::assertInstanceOf(DocumentCorrectionsRequestClientEmail::class, $sent);
        self::assertSame('client@example.test', $sent->getTo()[0]->getAddress());
        $items = $sent->getContext()['items'];
        self::assertSame([DocumentType::ID_CARD->getLabel(), DocumentType::PROOF_OF_ADDRESS->getLabel()], array_column($items, 'label'));
        self::assertSame(DocumentRejectionReason::EXPIRED->getLabel(), $items[0]['reason']);
        self::assertSame('https://kysure.test/portail/dossier', $sent->getContext()['kysure_portal_url']);
    }

    public function testAPieceAlreadyRedepositedIsNotAskedAgain(): void
    {
        $idCard = $this->document(DocumentType::ID_CARD);
        $idCard->rejectByAdvisor(DocumentRejectionReason::ILLEGIBLE, null, 'Conseiller');
        $this->folder->requestCorrectionsFromClient('Conseiller');
        // Le client a été plus rapide que la file d'envoi.
        $idCard->markAsUploaded('kyc/nouveau.jpg', 'nouveau.jpg', 'image/jpeg', 10);

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects($this->never())->method('send');

        $this->handler($mailer)($this->message([$idCard]));
    }

    public function testWithoutAClientAddressNothingIsSentAndNothingIsRetried(): void
    {
        $idCard = $this->document(DocumentType::ID_CARD);
        $idCard->rejectByAdvisor(DocumentRejectionReason::ILLEGIBLE, null, 'Conseiller');
        $this->folder->requestCorrectionsFromClient('Conseiller');

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects($this->never())->method('send');

        $this->handler($mailer, null)($this->message([$idCard]));
    }
}
