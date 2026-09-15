<?php

declare(strict_types=1);

namespace App\Domain\Support\Entity;

use App\Domain\Common\Attribute\Encrypted;
use App\Domain\Support\Enum\SupportSenderType;
use App\Infrastructure\Trait\GenerateSlugPrefixedTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'support_messages')]
class SupportMessage
{
    use GenerateSlugPrefixedTrait;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    public ?Uuid $id = null {
        get => $this->id;
    }

    #[ORM\Column(type: Types::STRING, length: 255, unique: true)]
    public private(set) string $slugId;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public private(set) ?\DateTimeImmutable $readAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    public private(set) \DateTimeImmutable $createdAt;

    #[ORM\Column(length: 255, nullable: true)]
    public private(set) ?string $attachmentStoragePath = null;

    #[Encrypted]
    #[ORM\Column(length: 255, nullable: true)]
    public private(set) ?string $attachmentFilename = null;

    #[ORM\Column(length: 100, nullable: true)]
    public private(set) ?string $attachmentMimeType = null;

    #[ORM\Column(nullable: true)]
    public private(set) ?int $attachmentSize = null;

    private function __construct(#[ORM\ManyToOne(targetEntity: SupportThread::class, inversedBy: 'messages')]
        #[ORM\JoinColumn(nullable: false)]
        public private(set) SupportThread $thread, #[ORM\Column(type: 'string', enumType: SupportSenderType::class)]
        public private(set) SupportSenderType $senderType, #[Encrypted]
        #[ORM\Column(type: Types::TEXT)]
        public private(set) string $content)
    {
        $this->slugId = $this->generate_ulid_prefixed('sup_msg_');
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        // DDD: Le message informe son Thread parent de son existence
        $this->thread->addMessage($this);
    }

    /**
     * 🪄 Named Constructor : Écrit un nouveau message.
     */
    public static function write(SupportThread $thread, SupportSenderType $senderType, string $content): self
    {
        return new self($thread, $senderType, $content);
    }

    // --- LOGIQUE MÉTIER ---

    public function markAsRead(): void
    {
        if (!$this->readAt instanceof \DateTimeImmutable) {
            $this->readAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        }
    }

    public function attachFile(string $storagePath, string $filename, string $mimeType, int $size): void
    {
        $this->attachmentStoragePath = $storagePath;
        $this->attachmentFilename = $filename;
        $this->attachmentMimeType = $mimeType;
        $this->attachmentSize = $size;
    }

    public function hasAttachment(): bool
    {
        return null !== $this->attachmentStoragePath;
    }
}
