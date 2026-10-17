<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\EventSubscriber;

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

    private function entityManagerReturning(UnitOfWork $unitOfWork): EntityManagerInterface
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getUnitOfWork')->willReturn($unitOfWork);

        return $entityManager;
    }
}
