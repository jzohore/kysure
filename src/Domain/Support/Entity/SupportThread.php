<?php

declare(strict_types=1);

namespace App\Domain\Support\Entity;

use App\Domain\Support\Enum\SupportCategory;
use App\Domain\Support\Enum\SupportPriority;
use App\Domain\Support\Enum\SupportSenderType;
use App\Domain\Support\Enum\SupportThreadStatus;
use App\Domain\Support\Enum\SupportTopic;
use App\Domain\User\Entity\Admin;
use App\Domain\User\Entity\User;
use App\Domain\Workspace\Entity\Workspace;
use App\Infrastructure\Trait\GenerateSlugPrefixedTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'support_threads')]
class SupportThread
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

    #[ORM\Column(type: 'string', enumType: SupportThreadStatus::class)]
    public private(set) SupportThreadStatus $status = SupportThreadStatus::OPEN;

    /**
     * @var Collection<int, SupportMessage>
     */
    #[ORM\OneToMany(targetEntity: SupportMessage::class, mappedBy: 'thread', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['createdAt' => 'ASC', 'id' => 'ASC'])]
    public private(set) Collection $messages;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    public private(set) \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    public private(set) \DateTimeImmutable $updatedAt;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    public private(set) bool $closureWarningSent = false;

    #[ORM\Column(type: 'string', enumType: SupportPriority::class, options: ['default' => 'normal'])]
    public private(set) SupportPriority $priority = SupportPriority::NORMAL;

    #[ORM\ManyToOne(targetEntity: Admin::class)]
    #[ORM\JoinColumn(nullable: true)]
    public private(set) ?Admin $assignedTo = null;

    /**
     * Constructeur privé pour forcer l'utilisation de la factory method.
     *
     * @throws \DateMalformedStringException
     */
    private function __construct(#[ORM\ManyToOne(targetEntity: Workspace::class, inversedBy: 'supportThread')]
        #[ORM\JoinColumn(nullable: false)]
        public private(set) Workspace $workspace, #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'supportThread')]
        #[ORM\JoinColumn(nullable: false)]
        public private(set) User $user, #[ORM\Column(type: Types::STRING, length: 100)]
        public private(set) string $category, #[ORM\Column(type: Types::STRING, length: 100)]
        public private(set) string $topic, #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
        public private(set) ?string $urlContext = null)
    {
        $this->messages = new ArrayCollection();
        $this->slugId = $this->generate_ulid_prefixed('sup_thr_');
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    /**
     * 🪄 Named Constructor : Initialise un nouveau ticket.
     *
     * @throws \DateMalformedStringException
     */
    public static function open(Workspace $workspace, User $user, string $category, string $topic, ?string $urlContext = null): self
    {
        return new self($workspace, $user, $category, $topic, $urlContext);
    }

    // --- LOGIQUE MÉTIER ---

    public function resolve(): void
    {
        $this->status = SupportThreadStatus::RESOLVED;
        $this->updateTimestamp();
    }

    public function reopen(): void
    {
        $this->status = SupportThreadStatus::OPEN;
        $this->updateTimestamp();
    }

    /**
     * Méthode interne appelée automatiquement par SupportMessage lors de sa création.
     */
    public function addMessage(SupportMessage $message): void
    {
        if (!$this->messages->contains($message)) {
            $this->messages->add($message);
            $this->updateTimestamp();
        }
    }

    private function updateTimestamp(): void
    {
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function markAsReadByClient(): void
    {
        foreach ($this->messages as $message) {
            // Si le message vient du support ET qu'il n'est pas encore lu
            if (SupportSenderType::ADMIN === $message->senderType && null === $message->readAt) {
                $message->markAsRead();
            }
        }
    }

    public function getTopicTitle(): string
    {
        return SupportTopic::from($this->topic)->getTitle();
    }

    public function getCategoryTitle(): string
    {
        return SupportCategory::from($this->category)->getTitle();
    }

    public function assignTo(?Admin $admin): void
    {
        $this->assignedTo = $admin;
    }

    public function changePriority(SupportPriority $priority): void
    {
        $this->priority = $priority;
    }

    public function markClosureWarningAsSent(): void
    {
        $this->closureWarningSent = true;
    }

    public function resetClosureWarning(): void
    {
        $this->closureWarningSent = false;
    }
}
