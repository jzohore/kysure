<?php

declare(strict_types=1);

namespace App\Domain\Billing\Entity;

use App\Domain\Billing\Enum\Plan;
use App\Domain\Billing\Enum\SubscriptionStatus;
use App\Domain\Workspace\Entity\Workspace;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;

use function Symfony\Component\Clock\now;

use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'subscriptions')]
class Subscription
{
    public const int PLAN_MAX_USERS = 5;
    public const int PLAN_MAX_SEARCHES_PER_MONTH = 500;
    public const int PLAN_MAX_MONITORING = 500;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    public ?Uuid $id = null {
        get => $this->id;
    }

    // Début de la période de facturation en cours
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    public private(set) \DateTimeImmutable $currentPeriodStart;

    // Fin de la période de facturation (Très important pour couper l'accès !)
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    public private(set) \DateTimeImmutable $currentPeriodEnd;

    // Vrai si le client a cliqué sur "Annuler" mais que le mois n'est pas fini
    #[ORM\Column(type: Types::BOOLEAN)]
    public private(set) bool $cancelAtPeriodEnd = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    public private(set) \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public private(set) \DateTimeImmutable $updateAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public private(set) ?\DateTimeImmutable $trialEndsAt = null;

    #[ORM\Column(type: Types::STRING, nullable: true)]
    public private(set) ?string $reason = null;

    /** Nombre de sièges facturés (quantity côté Stripe). */
    #[ORM\Column(type: Types::INTEGER, options: ['default' => 1])]
    public private(set) int $seatsCount = 1;

    /** Non nul = abonnement suspendu (pause_collection côté Stripe), accès gelé. */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public private(set) ?\DateTimeImmutable $pausedAt = null;

    /** Non nul = l'offre de fidélité (au moment d'une tentative de résiliation) a déjà été utilisée. */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public private(set) ?\DateTimeImmutable $retentionOfferClaimedAt = null;

    public function isPaused(): bool
    {
        return $this->pausedAt instanceof \DateTimeImmutable;
    }

    public function pause(): void
    {
        $this->pausedAt = now();
        $this->updateAt = now();
    }

    public function resume(): void
    {
        $this->pausedAt = null;
        $this->updateAt = now();
    }

    public function canClaimRetentionOffer(): bool
    {
        return !$this->retentionOfferClaimedAt instanceof \DateTimeImmutable;
    }

    public function claimRetentionOffer(): void
    {
        if (!$this->canClaimRetentionOffer()) {
            throw new \DomainException('L\'offre de fidélité a déjà été utilisée pour cet abonnement.');
        }

        $this->retentionOfferClaimedAt = now();
        // Le client reste : on annule toute résiliation programmée.
        $this->cancelAtPeriodEnd = false;
        $this->reason = null;
        $this->updateAt = now();
    }

    public function updateSeats(int $seatsCount): void
    {
        $this->seatsCount = max(1, $seatsCount);
        $this->updateAt = now();
    }

    /**
     * 1. On passe le constructeur en PRIVATE.
     * Seule la classe elle-même a le droit de s'instancier.
     *
     * @throws \Exception
     */
    private function __construct(
        #[ORM\OneToOne(targetEntity: Workspace::class, inversedBy: 'subscription')]
        #[ORM\JoinColumn(nullable: false)]
        public private(set) Workspace $workspace,
        #[ORM\Column(type: Types::STRING, unique: true, nullable: true)]
        public private(set) ?string $stripeSubscriptionId,
        #[ORM\Column(type: Types::STRING)]
        public private(set) string $stripePriceId,
        #[ORM\Column(type: Types::STRING)]
        public private(set) string $planReference,
        #[ORM\Column(type: Types::STRING, enumType: SubscriptionStatus::class)]
        public private(set) SubscriptionStatus $status,
    ) {
        $this->createdAt = now();
        $this->updateAt = now();
    }

    /**
     * 2. Le Named Constructor (La Factory statique).
     */
    public static function create(
        Workspace $workspace,
        string $stripeSubscriptionId,
        string $stripePriceId,
        string $planReference,
        SubscriptionStatus $status = SubscriptionStatus::INCOMPLETE,
    ): self {
        $subscription = new self(
            $workspace,
            $stripeSubscriptionId,
            $stripePriceId,
            $planReference,
            $status
        );

        // Initialisation des dates par défaut lors de la création
        $subscription->currentPeriodStart = new \DateTimeImmutable();

        // On donne 1h de battement le temps que le Webhook Stripe confirme le 1er paiement
        $subscription->currentPeriodEnd = new \DateTimeImmutable()->modify('+1 hour');
        $subscription->cancelAtPeriodEnd = false;

        return $subscription;
    }

    /**
     * Abonnement KYSURE « au siège » créé à la confirmation d'un Stripe Checkout.
     * Le nombre de sièges = quantity de la ligne d'abonnement Stripe.
     */
    public static function forPlan(
        Workspace $workspace,
        string $stripeSubscriptionId,
        string $stripePriceId,
        Plan $plan,
        int $seatsCount,
        SubscriptionStatus $status,
        \DateTimeImmutable $currentPeriodStart,
        \DateTimeImmutable $currentPeriodEnd,
    ): self {
        $subscription = new self(
            $workspace,
            $stripeSubscriptionId,
            $stripePriceId,
            $plan->value,
            $status,
        );

        $subscription->seatsCount = max($plan->getMinSeats(), $seatsCount);
        $subscription->currentPeriodStart = $currentPeriodStart;
        $subscription->currentPeriodEnd = $currentPeriodEnd;
        $subscription->cancelAtPeriodEnd = false;
        $subscription->trialEndsAt = SubscriptionStatus::TRIALING === $status ? $currentPeriodEnd : null;

        return $subscription;
    }

    public static function startCabinetTrial(
        Workspace $workspace,
        string $stripeSubscriptionId,
        string $stripePriceId,
    ): self {
        $now = new \DateTimeImmutable();
        $trialEnd = $now->modify('+30 days');

        // On appelle le constructeur privé
        $subscription = new self(
            $workspace,
            $stripeSubscriptionId,
            $stripePriceId,
            'kysure_cabinet_300', // Le nom de ton offre hardcodé
            SubscriptionStatus::TRIALING
        );

        // On initialise les dates spécifiques au trial
        $subscription->currentPeriodStart = $now;
        $subscription->currentPeriodEnd = $trialEnd;
        $subscription->trialEndsAt = $trialEnd;
        $subscription->cancelAtPeriodEnd = false;

        return $subscription;
    }

    /**
     * Méthode appelée UNIQUEMENT par ton contrôleur Webhook Stripe.
     */
    public function syncWithStripe(
        SubscriptionStatus $status,
        \DateTimeImmutable $periodStart,
        \DateTimeImmutable $periodEnd,
        bool $cancelAtPeriodEnd,
    ): void {
        $this->status = $status;
        $this->currentPeriodStart = $periodStart;
        $this->currentPeriodEnd = $periodEnd;
        $this->cancelAtPeriodEnd = $cancelAtPeriodEnd;
    }

    /**
     * Vérifie si le cabinet a le droit d'utiliser Kysure aujourd'hui.
     */
    public function isValid(): bool
    {
        // 0. Un abonnement suspendu ne donne pas accès.
        if ($this->isPaused()) {
            return false;
        }

        // 1. Le statut doit être Actif ou Trial
        if (!$this->status->isActive()) {
            return false;
        }

        // 2. La date de fin ne doit pas être dépassée
        // (On ajoute 24h de grâce en cas de retard de webhook Stripe)
        $gracePeriod = $this->currentPeriodEnd->modify('+1 day');

        return new \DateTimeImmutable() <= $gracePeriod;
    }

    /**
     * Retourne le nombre de jours restants pour l'essai gratuit.
     * Renvoie 0 si l'essai est terminé ou inactif.
     */
    public function getRemainingTrialDays(): int
    {
        // Si on n'est pas en période d'essai ou que la date n'est pas définie
        if (SubscriptionStatus::TRIALING !== $this->status || !$this->trialEndsAt instanceof \DateTimeImmutable) {
            return 0;
        }

        $now = new \DateTimeImmutable();

        // Si la date de fin est déjà passée
        if ($now > $this->trialEndsAt) {
            return 0;
        }

        // On calcule la différence en jours
        return (int) $now->diff($this->trialEndsAt)->days;
    }

    public function activateSubscription(string $stripeSubscriptionId): void
    {
        $this->stripeSubscriptionId = $stripeSubscriptionId;
        $this->status = SubscriptionStatus::ACTIVE;
        $now = new \DateTimeImmutable();
        $end = $now->modify('+1 month');
        $this->currentPeriodStart = $now;
        $this->currentPeriodEnd = $end;
        $this->trialEndsAt = null;
    }

    public function markAsPendingCancellation(string $reason): void
    {
        $this->cancelAtPeriodEnd = true;
        $this->reason = $reason;
    }

    /** Annule une résiliation programmée (le client reste, sans offre de fidélité associée). */
    public function cancelScheduledCancellation(): void
    {
        if (!$this->cancelAtPeriodEnd) {
            throw new \DomainException('Aucune résiliation n\'est programmée pour cet abonnement.');
        }

        $this->cancelAtPeriodEnd = false;
        $this->reason = null;
        $this->updateAt = now();
    }

    public function markAsTerminate(): void
    {
        $this->cancelAtPeriodEnd = false;
        $this->status = SubscriptionStatus::CANCELED;
        $this->stripeSubscriptionId = null;
    }

    public function syncSubscription(\DateTimeImmutable $currentPeriodEnd, SubscriptionStatus $status, bool $cancelPeriodEnd): void
    {
        $this->currentPeriodEnd = $currentPeriodEnd;
        $this->status = $status;
        $this->cancelAtPeriodEnd = $cancelPeriodEnd;
    }
}
