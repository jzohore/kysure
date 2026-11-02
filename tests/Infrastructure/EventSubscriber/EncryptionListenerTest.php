<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\EventSubscriber;

use App\Domain\Compliance\Entity\ComplianceDocument;
use App\Domain\Compliance\Entity\IndividualFolder;
use App\Infrastructure\EventSubscriber\EncryptionListener;
use App\Infrastructure\Security\MessageCipher;
use App\Tests\Application\ReflectionHelperTrait;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\UnitOfWork;
use PHPUnit\Framework\TestCase;

final class EncryptionListenerTest extends TestCase
{
    use ReflectionHelperTrait;

    private MessageCipher $cipher;
    private EncryptionListener $listener;

    protected function setUp(): void
    {
        $this->cipher = new MessageCipher(random_bytes(32));
        $this->listener = new EncryptionListener($this->cipher);
    }

    public function testPostLoadDecryptsAndAlignsSnapshotOnPlainText(): void
    {
        $folder = $this->createEntityState(IndividualFolder::class, [
            'firstName' => $this->cipher->encrypt('Jean'),
            'lastName' => $this->cipher->encrypt('Dupont'),
        ]);

        /** @var array<string, string> $snapshot */
        $snapshot = [];
        $unitOfWork = $this->createMock(UnitOfWork::class);
        $unitOfWork->expects($this->exactly(2))
            ->method('setOriginalEntityProperty')
            ->willReturnCallback(static function (int $oid, string $property, mixed $value) use (&$snapshot): void {
                $snapshot[$property] = $value;
            });

        $this->listener->postLoad(new PostLoadEventArgs($folder, $this->entityManagerReturning($unitOfWork)));

        self::assertSame('Jean', $folder->firstName);
        self::assertSame('Dupont', $folder->lastName);
        self::assertNull($folder->email);
        // 🛡️ L'instantané doit porter le clair : sinon l'entité paraît modifiée à chaque flush.
        self::assertSame(['firstName' => 'Jean', 'lastName' => 'Dupont'], $snapshot);
    }

    public function testPreUpdateEncryptsOnlyChangedFieldsAndKeepsEntityInPlainText(): void
    {
        $folder = $this->createEntityState(IndividualFolder::class, [
            'firstName' => 'Jean',
            'lastName' => 'Durand',
        ]);

        $changeSet = ['lastName' => ['Dupont', 'Durand']];
        $this->listener->preUpdate(new PreUpdateEventArgs($folder, $this->createStub(EntityManagerInterface::class), $changeSet));

        self::assertSame(['lastName'], array_keys($changeSet));
        self::assertNotSame('Durand', $changeSet['lastName'][1]);
        self::assertSame('Durand', $this->cipher->decrypt($changeSet['lastName'][1]));
        // L'ancienne valeur du change-set n'est pas touchée, et l'entité garde son clair.
        self::assertSame('Dupont', $changeSet['lastName'][0]);
        self::assertSame('Jean', $folder->firstName);
        self::assertSame('Durand', $folder->lastName);
    }

    public function testPreUpdateIgnoresEmptyValues(): void
    {
        $folder = $this->createEntityState(IndividualFolder::class, ['email' => null]);

        $changeSet = ['email' => ['jean@example.com', null]];
        $this->listener->preUpdate(new PreUpdateEventArgs($folder, $this->createStub(EntityManagerInterface::class), $changeSet));

        self::assertNull($changeSet['email'][1]);
    }

    public function testPersistEncryptsThenRestoresPlainTextAndAlignsSnapshot(): void
    {
        $folder = $this->createEntityState(IndividualFolder::class, ['firstName' => 'Jean']);
        $entityManager = $this->createStub(EntityManagerInterface::class);

        $this->listener->prePersist(new PrePersistEventArgs($folder, $entityManager));
        $encrypted = $folder->firstName;

        self::assertNotSame('Jean', $encrypted);
        self::assertSame('Jean', $this->cipher->decrypt((string) $encrypted));

        $unitOfWork = $this->createMock(UnitOfWork::class);
        $unitOfWork->expects($this->once())
            ->method('setOriginalEntityProperty')
            ->willReturnCallback(static function (int $oid, string $property, mixed $value): void {
                self::assertSame('firstName', $property);
                self::assertSame('Jean', $value);
            });

        $this->listener->postPersist(new PostPersistEventArgs($folder, $this->entityManagerReturning($unitOfWork)));

        self::assertSame('Jean', $folder->firstName);
    }

    public function testPersistEncryptsOcrArraysIntoAnEnvelopeThenRestoresThem(): void
    {
        $data = ['document_number' => 'AB123456', 'birth_date' => '1980-04-12'];
        $findings = ['Identité non correspondante : le nom sur la pièce (DUPONT) diffère de celui du dossier.'];
        $document = $this->createEntityState(ComplianceDocument::class, ['ocrData' => $data, 'ocrFindings' => $findings]);

        $this->listener->prePersist(new PrePersistEventArgs($document, $this->createStub(EntityManagerInterface::class)));

        // Ce qui part en base : une enveloppe, aucune donnée lisible.
        self::assertIsArray($document->ocrData);
        self::assertSame(['_enc'], array_keys($document->ocrData));
        self::assertStringNotContainsString('AB123456', json_encode($document->ocrData, \JSON_THROW_ON_ERROR));
        self::assertIsArray($document->ocrFindings);
        self::assertStringNotContainsString('DUPONT', json_encode($document->ocrFindings, \JSON_THROW_ON_ERROR));
        self::assertSame($data, json_decode($this->cipher->decrypt($document->ocrData['_enc']), true));

        $unitOfWork = $this->createStub(UnitOfWork::class);
        $this->listener->postPersist(new PostPersistEventArgs($document, $this->entityManagerReturning($unitOfWork)));

        self::assertSame($data, $document->ocrData);
        self::assertSame($findings, $document->ocrFindings);
    }

    public function testPostLoadDecryptsAnOcrEnvelopeAndAlignsSnapshotOnThePlainArray(): void
    {
        $data = ['last_name' => 'DUPONT', 'mrz' => ['line_1' => 'IDFRA<<<<']];
        $document = $this->createEntityState(ComplianceDocument::class, [
            'ocrData' => ['_enc' => $this->cipher->encrypt(json_encode($data, \JSON_THROW_ON_ERROR))],
        ]);

        /** @var array<string, mixed> $snapshot */
        $snapshot = [];
        $unitOfWork = $this->createMock(UnitOfWork::class);
        $unitOfWork->expects($this->once())
            ->method('setOriginalEntityProperty')
            ->willReturnCallback(static function (int $oid, string $property, mixed $value) use (&$snapshot): void {
                $snapshot[$property] = $value;
            });

        $this->listener->postLoad(new PostLoadEventArgs($document, $this->entityManagerReturning($unitOfWork)));

        self::assertSame($data, $document->ocrData);
        self::assertNull($document->ocrFindings);
        self::assertSame(['ocrData' => $data], $snapshot);
    }

    public function testPostLoadLeavesAnOcrAnalysisStillStoredInClearUntouched(): void
    {
        // Ligne antérieure au chiffrement (avant `app:ocr:encrypt-stored-data`) : lisible telle quelle.
        $document = $this->createEntityState(ComplianceDocument::class, ['ocrData' => ['last_name' => 'DUPONT']]);

        $unitOfWork = $this->createMock(UnitOfWork::class);
        $unitOfWork->expects($this->never())->method('setOriginalEntityProperty');

        $this->listener->postLoad(new PostLoadEventArgs($document, $this->entityManagerReturning($unitOfWork)));

        self::assertSame(['last_name' => 'DUPONT'], $document->ocrData);
    }

    public function testPreUpdateEncryptsAChangedOcrArrayButNeverTwice(): void
    {
        $document = $this->createEntityState(ComplianceDocument::class, ['ocrData' => ['last_name' => 'DURAND']]);
        $alreadyEncrypted = ['_enc' => $this->cipher->encrypt('{"a":1}')];

        $changeSet = [
            'ocrData' => [['last_name' => 'DUPONT'], ['last_name' => 'DURAND']],
            'ocrFindings' => [null, $alreadyEncrypted],
        ];
        $this->listener->preUpdate(new PreUpdateEventArgs($document, $this->createStub(EntityManagerInterface::class), $changeSet));

        self::assertSame(['_enc'], array_keys($changeSet['ocrData'][1]));
        self::assertSame(['last_name' => 'DURAND'], json_decode($this->cipher->decrypt($changeSet['ocrData'][1]['_enc']), true));
        // Une enveloppe déjà chiffrée n'est pas rechiffrée.
        self::assertSame($alreadyEncrypted, $changeSet['ocrFindings'][1]);
        // L'entité garde son clair.
        self::assertSame(['last_name' => 'DURAND'], $document->ocrData);
    }

    public function testEmptyAndNullOcrValuesAreNotEncrypted(): void
    {
        $document = $this->createEntityState(ComplianceDocument::class, ['ocrData' => [], 'ocrFindings' => null]);

        $this->listener->prePersist(new PrePersistEventArgs($document, $this->createStub(EntityManagerInterface::class)));

        self::assertSame([], $document->ocrData);
        self::assertNull($document->ocrFindings);
    }

    private function entityManagerReturning(UnitOfWork $unitOfWork): EntityManagerInterface
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getUnitOfWork')->willReturn($unitOfWork);

        return $entityManager;
    }
}
