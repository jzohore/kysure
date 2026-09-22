<?php

declare(strict_types=1);

namespace App\Domain\Feedback\Entity;

use App\Domain\User\Entity\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;

use function Symfony\Component\Clock\now;

use Symfony\Component\Uid\Uuid;
use Webmozart\Assert\Assert;

/**
 * Retour du testeur pilote (widget flottant, visible uniquement en dev/staging — jamais en
 * prod, cf. gabarit `base_app.html.twig`). Volontairement minimal pour un seul testeur : pas de
 * statut, pas d'assignation, pas de réponse en fil — juste une liste que le fondateur consulte
 * dans le back-office (`/admin/feedback`).
 */
#[ORM\Entity]
#[ORM\Table(name: 'feedbacks')]
class Feedback
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    public private(set) ?Uuid $id = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    public private(set) \DateTimeImmutable $createdAt;

    private function __construct(
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public private(set) User $submittedBy,
        #[ORM\Column(type: Types::TEXT)]
        public private(set) string $message,
        #[ORM\Column(type: Types::STRING, length: 2048)]
        public private(set) string $pageUrl,
        #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
        public private(set) ?string $pageTitle = null,
    ) {
        Assert::notWhitespaceOnly($message, 'Le message ne peut pas être vide.');
        $this->createdAt = now();
    }

    public static function submit(User $submittedBy, string $message, string $pageUrl, ?string $pageTitle): self
    {
        return new self($submittedBy, trim($message), $pageUrl, $pageTitle);
    }
}
