<?php

declare(strict_types=1);

namespace App\Infrastructure\Service\Payment\Stripe;

use App\Application\User\UseCase\UpdateStripeCustomerIdUseCase;
use App\Domain\User\Entity\User;
use Stripe\Coupon;
use Stripe\Customer;
use Stripe\Exception\ApiErrorException;
use Stripe\Invoice;
use Stripe\Product;
use Stripe\Stripe;
use Stripe\Subscription;
use Webmozart\Assert\Assert;

readonly class StripeService
{
    /** Coupon de fidélité proposé au moment d'une tentative de résiliation. */
    public const string RETENTION_COUPON_ID = 'kysure_retention_30_3m';
    public const int RETENTION_PERCENT_OFF = 30;
    public const int RETENTION_DURATION_MONTHS = 3;

    public function __construct(
        private string $stripeSecretKey,
        private UpdateStripeCustomerIdUseCase $stripeCustomerIdUseCase,
    ) {
    }

    /**
     * Enregistre un utilisateur en tant que "Customer" sur Stripe.
     */
    public function createStripeCustomer(User $user): string
    {
        // 🛡️ Idempotence applicative : on ne recrée jamais un client déjà rattaché.
        if (null !== $user->profile->stripeCustomerId) {
            return $user->profile->stripeCustomerId;
        }

        try {
            Assert::notNull($user->email);
            Assert::notNull($user->getFullName());
            Assert::notNull($user->id);
            Stripe::setApiKey($this->stripeSecretKey);
            $customer = Customer::create(
                [
                    'email' => $user->email,
                    'name' => $user->getFullName(), // Ou le nom du Workspace selon ton architecture
                    'metadata' => [
                        'user_id' => (string) $user->id, // Indispensable pour retrouver tes petits
                    ],
                ],
                // 🛡️ Idempotence côté Stripe : un rejeu (retry Messenger, incident
                // réseau) réutilise le même client au lieu d'en créer un doublon.
                ['idempotency_key' => 'create_customer_' . $user->id],
            );

            Assert::notNull($customer->id);
            ($this->stripeCustomerIdUseCase)($user, $customer->id);

            return $customer->id;
        } catch (ApiErrorException $e) {
            // Gérer l'erreur proprement pour ne pas faire planter ton app
            throw new \RuntimeException('Impossible d\'enregistrer le client sur Stripe : ' . $e->getMessage(), $e->getCode(), $e);
        }
    }

    public function getSubscription(string $subscriptionId): Subscription
    {
        try {
            Stripe::setApiKey($this->stripeSecretKey);

            return Subscription::retrieve($subscriptionId);
        } catch (ApiErrorException $e) {
            // Gérer l'erreur proprement pour ne pas faire planter ton app
            throw new \RuntimeException('Impossible de recupérer l\'abonnement sur Stripe : ' . $e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * @return array<int, array{date: \DateTimeImmutable, amount: float|int, status: string|null, pdf_url: string|null}>
     */
    public function getInvoicesBySub(string $subscriptionId): array
    {
        try {
            Stripe::setApiKey($this->stripeSecretKey);
            $invoices = Invoice::all([
                'subscription' => $subscriptionId,
                'limit' => 12,
            ]);

            $invoiceHistory = [];

            foreach ($invoices->data as $invoice) {
                // On ignore les factures à 0€ (comme celles des périodes d'essai gratuites)
                if (0 === $invoice->amount_paid) {
                    continue;
                }

                $invoiceHistory[] = [
                    'date' => new \DateTimeImmutable()->setTimestamp($invoice->created),
                    'amount' => $invoice->amount_paid / 100, // Le montant RÉEL payé ce mois-là
                    'status' => $invoice->status, // ex: 'paid', 'open', 'void'
                    'pdf_url' => $invoice->invoice_pdf, // Le lien direct vers le PDF hébergé par Stripe !
                ];
            }

            return $invoiceHistory;
        } catch (ApiErrorException $e) {
            // Gérer l'erreur proprement pour ne pas faire planter ton app
            throw new \RuntimeException('Impossible de recupérer l\'abonnement sur Stripe : ' . $e->getMessage(), $e->getCode(), $e);
        }
    }

    public function cancelSubscription(string $subscriptionId, string $reason): Subscription
    {
        try {
            Stripe::setApiKey($this->stripeSecretKey);

            return Subscription::update($subscriptionId, [
                'cancel_at_period_end' => true,
                'metadata' => ['cancel_reason' => $reason],
            ]);
        } catch (ApiErrorException $e) {
            // Gérer l'erreur proprement pour ne pas faire planter ton app
            throw new \RuntimeException('Impossible de cancel l\'abonnement sur Stripe : ' . $e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Annule une résiliation programmée (sans coupon de fidélité associé).
     */
    public function uncancelSubscription(string $stripeSubscriptionId): void
    {
        try {
            Stripe::setApiKey($this->stripeSecretKey);
            Subscription::update($stripeSubscriptionId, [
                'cancel_at_period_end' => false,
            ]);
        } catch (ApiErrorException $e) {
            throw new \RuntimeException('Impossible d\'annuler la résiliation sur Stripe : ' . $e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Suspend l'abonnement : plus de facture émise tant que la pause dure.
     */
    public function pauseSubscription(string $stripeSubscriptionId): void
    {
        try {
            Stripe::setApiKey($this->stripeSecretKey);
            Subscription::update($stripeSubscriptionId, [
                'pause_collection' => ['behavior' => 'void'],
            ]);
        } catch (ApiErrorException $e) {
            throw new \RuntimeException('Impossible de suspendre l\'abonnement sur Stripe : ' . $e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Lève la suspension : la facturation reprend au cycle suivant.
     */
    public function resumeSubscription(string $stripeSubscriptionId): void
    {
        try {
            Stripe::setApiKey($this->stripeSecretKey);
            Subscription::update($stripeSubscriptionId, [
                'pause_collection' => null,
            ]);
        } catch (ApiErrorException $e) {
            throw new \RuntimeException('Impossible de reprendre l\'abonnement sur Stripe : ' . $e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Applique le coupon de fidélité (-30 % pendant 3 mois) à l'abonnement.
     * Crée le coupon Stripe s'il n'existe pas encore (idempotent).
     */
    public function applyRetentionCoupon(string $stripeSubscriptionId): void
    {
        try {
            Stripe::setApiKey($this->stripeSecretKey);
            $this->ensureRetentionCoupon();

            Subscription::update($stripeSubscriptionId, [
                'coupon' => self::RETENTION_COUPON_ID,
                // Le client reste : on annule toute résiliation programmée.
                'cancel_at_period_end' => false,
            ]);
        } catch (ApiErrorException $e) {
            throw new \RuntimeException('Impossible d\'appliquer l\'offre de fidélité sur Stripe : ' . $e->getMessage(), $e->getCode(), $e);
        }
    }

    private function ensureRetentionCoupon(): void
    {
        try {
            Coupon::retrieve(self::RETENTION_COUPON_ID);
        } catch (ApiErrorException) {
            Coupon::create([
                'id' => self::RETENTION_COUPON_ID,
                'percent_off' => self::RETENTION_PERCENT_OFF,
                'duration' => 'repeating',
                'duration_in_months' => self::RETENTION_DURATION_MONTHS,
                'name' => 'Fidélité KYSURE — -30 % pendant 3 mois',
            ]);
        }
    }

    /**
     * Ajuste le nombre de sièges facturés (quantity de la ligne d'abonnement),
     * avec facturation au prorata sur la prochaine facture.
     */
    public function updateSubscriptionSeats(string $stripeSubscriptionId, int $quantity): void
    {
        try {
            Stripe::setApiKey($this->stripeSecretKey);

            $subscription = Subscription::retrieve($stripeSubscriptionId);
            $itemId = $subscription->items->data[0]->id ?? null;
            Assert::stringNotEmpty($itemId, 'Ligne d\'abonnement Stripe introuvable.');

            Subscription::update($stripeSubscriptionId, [
                'items' => [['id' => $itemId, 'quantity' => max(1, $quantity)]],
                'proration_behavior' => 'create_prorations',
            ]);
        } catch (ApiErrorException $e) {
            throw new \RuntimeException('Impossible de mettre à jour les sièges sur Stripe : ' . $e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Crée un produit + son prix par défaut sur Stripe pour une offre KYSURE
     * (modèle Phase 1). Renvoie les deux identifiants.
     *
     * @param array<string, string> $metadata
     *
     * @return array{product_id: string, price_id: string}
     */
    public function createPricingPlan(
        string $name,
        ?string $description,
        int $unitAmountCents,
        bool $recurring,
        array $metadata = [],
    ): array {
        try {
            Stripe::setApiKey($this->stripeSecretKey);

            $priceData = [
                'currency' => 'eur',
                'unit_amount' => $unitAmountCents,
            ];
            if ($recurring) {
                $priceData['recurring'] = ['interval' => 'month'];
            }

            $product = Product::create([
                'name' => $name,
                'description' => $description ?? $name,
                'metadata' => $metadata,
                'default_price_data' => $priceData,
            ]);

            $priceRaw = $product->default_price;
            $priceId = is_string($priceRaw) ? $priceRaw : $priceRaw?->id;

            Assert::stringNotEmpty($product->id, 'Stripe n\'a pas retourné d\'ID de produit.');
            Assert::stringNotEmpty($priceId, 'Stripe n\'a pas retourné d\'ID de prix.');

            return ['product_id' => $product->id, 'price_id' => $priceId];
        } catch (ApiErrorException $e) {
            throw new \RuntimeException('Impossible de créer l\'offre sur Stripe : ' . $e->getMessage(), $e->getCode(), $e);
        }
    }
}
