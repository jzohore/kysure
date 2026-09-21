<?php

declare(strict_types=1);

namespace App\Domain\Suitability\Enum;

/**
 * Les 22 questions du questionnaire profil investisseur (§3), dans l'ordre de présentation
 * au client (l'ordre des cases fait foi pour {@see self::cases()}).
 *
 * ⚠️ Le libellé exact de chaque question est un brouillon de travail, pas encore relu avec
 * le CGP cofondateur — à valider avant mise en production du questionnaire (lot 1).
 */
enum QuestionKey: string
{
    // --- Connaissances financières (§3.1) — une par famille de produit ---
    case KNOWLEDGE_OPCVM_ETF = 'knowledge_opcvm_etf';
    case KNOWLEDGE_TITRES_VIFS = 'knowledge_titres_vifs';
    case KNOWLEDGE_ASSURANCE_VIE = 'knowledge_assurance_vie';
    case KNOWLEDGE_IMMOBILIER_SCPI = 'knowledge_immobilier_scpi';
    case KNOWLEDGE_PRODUITS_COMPLEXES = 'knowledge_produits_complexes';

    // --- Expérience d'investissement (§3.2) ---
    case EXPERIENCE_PRODUCTS_HELD = 'experience_products_held';
    case EXPERIENCE_TRANSACTION_FREQUENCY = 'experience_transaction_frequency';
    case EXPERIENCE_APPROXIMATE_AMOUNT = 'experience_approximate_amount';
    case EXPERIENCE_YEARS = 'experience_years';
    case EXPERIENCE_TRANSACTION_COUNT = 'experience_transaction_count';
    case EXPERIENCE_HAS_EXPERIENCED_LOSSES = 'experience_has_experienced_losses';

    // --- Tolérance au risque (§3.4) — 3 scénarios de perte ---
    case TOLERANCE_REACTION_MINUS_10 = 'tolerance_reaction_minus_10';
    case TOLERANCE_REACTION_MINUS_20 = 'tolerance_reaction_minus_20';
    case TOLERANCE_REACTION_SIGNIFICANT_LOSS = 'tolerance_reaction_significant_loss';

    // --- Situation financière / capacité à subir des pertes (§3.5) ---
    case CAPACITY_ANNUAL_INCOME = 'capacity_annual_income';
    case CAPACITY_ANNUAL_EXPENSES = 'capacity_annual_expenses';
    case CAPACITY_NET_WORTH = 'capacity_net_worth';
    case CAPACITY_AVAILABLE_LIQUIDITY = 'capacity_available_liquidity';
    case CAPACITY_AMOUNT_TO_INVEST = 'capacity_amount_to_invest';
    case CAPACITY_HORIZON = 'capacity_horizon';

    // --- Préférences de durabilité (§3.7) ---
    case SUSTAINABILITY_PREFERENCE = 'sustainability_preference';
    case SUSTAINABILITY_CONSTRAINTS = 'sustainability_constraints';

    public function getDimension(): AssessmentDimension
    {
        return match ($this) {
            self::KNOWLEDGE_OPCVM_ETF,
            self::KNOWLEDGE_TITRES_VIFS,
            self::KNOWLEDGE_ASSURANCE_VIE,
            self::KNOWLEDGE_IMMOBILIER_SCPI,
            self::KNOWLEDGE_PRODUITS_COMPLEXES => AssessmentDimension::KNOWLEDGE,

            self::EXPERIENCE_PRODUCTS_HELD,
            self::EXPERIENCE_TRANSACTION_FREQUENCY,
            self::EXPERIENCE_APPROXIMATE_AMOUNT,
            self::EXPERIENCE_YEARS,
            self::EXPERIENCE_TRANSACTION_COUNT,
            self::EXPERIENCE_HAS_EXPERIENCED_LOSSES => AssessmentDimension::EXPERIENCE,

            self::TOLERANCE_REACTION_MINUS_10,
            self::TOLERANCE_REACTION_MINUS_20,
            self::TOLERANCE_REACTION_SIGNIFICANT_LOSS => AssessmentDimension::TOLERANCE,

            self::CAPACITY_ANNUAL_INCOME,
            self::CAPACITY_ANNUAL_EXPENSES,
            self::CAPACITY_NET_WORTH,
            self::CAPACITY_AVAILABLE_LIQUIDITY,
            self::CAPACITY_AMOUNT_TO_INVEST,
            self::CAPACITY_HORIZON => AssessmentDimension::CAPACITY,

            self::SUSTAINABILITY_PREFERENCE,
            self::SUSTAINABILITY_CONSTRAINTS => AssessmentDimension::SUSTAINABILITY,
        };
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::KNOWLEDGE_OPCVM_ETF => 'Comment évaluez-vous votre connaissance des OPCVM / ETF ?',
            self::KNOWLEDGE_TITRES_VIFS => 'Comment évaluez-vous votre connaissance des actions et obligations en direct ?',
            self::KNOWLEDGE_ASSURANCE_VIE => 'Comment évaluez-vous votre connaissance de l\'assurance-vie ?',
            self::KNOWLEDGE_IMMOBILIER_SCPI => 'Comment évaluez-vous votre connaissance de l\'immobilier indirect (SCPI...) ?',
            self::KNOWLEDGE_PRODUITS_COMPLEXES => 'Comment évaluez-vous votre connaissance des produits structurés, dérivés ou du private equity ?',

            self::EXPERIENCE_PRODUCTS_HELD => 'Quels types de placements avez-vous déjà détenus ?',
            self::EXPERIENCE_TRANSACTION_FREQUENCY => 'À quelle fréquence avez-vous investi jusqu\'ici ?',
            self::EXPERIENCE_APPROXIMATE_AMOUNT => 'Quel montant total avez-vous déjà investi, approximativement ?',
            self::EXPERIENCE_YEARS => 'Depuis combien d\'années investissez-vous ?',
            self::EXPERIENCE_TRANSACTION_COUNT => 'Combien d\'opérations d\'investissement avez-vous réalisées, approximativement ?',
            self::EXPERIENCE_HAS_EXPERIENCED_LOSSES => 'Avez-vous déjà connu une perte sur un de vos placements ?',

            self::TOLERANCE_REACTION_MINUS_10 => 'Votre portefeuille perd 10 % de sa valeur en quelques semaines. Que faites-vous ?',
            self::TOLERANCE_REACTION_MINUS_20 => 'Votre investissement perd temporairement 20 % de sa valeur. Que faites-vous ?',
            self::TOLERANCE_REACTION_SIGNIFICANT_LOSS => 'Votre investissement peut subir une perte importante et durable. Que faites-vous ?',

            self::CAPACITY_ANNUAL_INCOME => 'Quels sont vos revenus annuels, approximativement ?',
            self::CAPACITY_ANNUAL_EXPENSES => 'Quelles sont vos charges annuelles, approximativement ?',
            self::CAPACITY_NET_WORTH => 'Quel est votre patrimoine financier, hors résidence principale ?',
            self::CAPACITY_AVAILABLE_LIQUIDITY => 'Quel montant de liquidités avez-vous disponible aujourd\'hui ?',
            self::CAPACITY_AMOUNT_TO_INVEST => 'Quel montant envisagez-vous d\'investir ?',
            self::CAPACITY_HORIZON => 'Sur quel horizon envisagez-vous cet investissement ?',

            self::SUSTAINABILITY_PREFERENCE => 'Êtes-vous intéressé par des investissements durables (ESG) ?',
            self::SUSTAINABILITY_CONSTRAINTS => 'Avez-vous des contraintes ou exclusions spécifiques à préciser ?',
        };
    }

    /**
     * Texte d'aide affiché sous la question, ou `null` si la question se suffit à elle-même.
     */
    public function getHelpText(): ?string
    {
        return match ($this) {
            self::EXPERIENCE_PRODUCTS_HELD => 'Plusieurs réponses possibles.',
            self::CAPACITY_NET_WORTH => 'Hors résidence principale : placements financiers, épargne, biens immobiliers locatifs...',
            self::SUSTAINABILITY_CONSTRAINTS => 'Facultatif — laissez vide si vous n\'avez pas de contrainte particulière.',
            default => null,
        };
    }

    /**
     * Une réponse est-elle obligatoire pour continuer ? Seule la précision libre sur les
     * contraintes de durabilité est facultative — tout le reste est requis par le §3.
     */
    public function isRequired(): bool
    {
        return self::SUSTAINABILITY_CONSTRAINTS !== $this;
    }

    public function answerType(): AssessmentAnswerType
    {
        return match ($this) {
            self::KNOWLEDGE_OPCVM_ETF,
            self::KNOWLEDGE_TITRES_VIFS,
            self::KNOWLEDGE_ASSURANCE_VIE,
            self::KNOWLEDGE_IMMOBILIER_SCPI,
            self::KNOWLEDGE_PRODUITS_COMPLEXES,
            self::EXPERIENCE_TRANSACTION_FREQUENCY,
            self::TOLERANCE_REACTION_MINUS_10,
            self::TOLERANCE_REACTION_MINUS_20,
            self::TOLERANCE_REACTION_SIGNIFICANT_LOSS => AssessmentAnswerType::SINGLE_CHOICE_INT,

            self::CAPACITY_HORIZON,
            self::SUSTAINABILITY_PREFERENCE => AssessmentAnswerType::SINGLE_CHOICE_STRING,

            self::EXPERIENCE_PRODUCTS_HELD => AssessmentAnswerType::MULTI_CHOICE_STRING,

            self::EXPERIENCE_YEARS,
            self::EXPERIENCE_TRANSACTION_COUNT => AssessmentAnswerType::INTEGER,

            self::EXPERIENCE_APPROXIMATE_AMOUNT,
            self::CAPACITY_ANNUAL_INCOME,
            self::CAPACITY_ANNUAL_EXPENSES,
            self::CAPACITY_NET_WORTH,
            self::CAPACITY_AVAILABLE_LIQUIDITY,
            self::CAPACITY_AMOUNT_TO_INVEST => AssessmentAnswerType::DECIMAL,

            self::EXPERIENCE_HAS_EXPERIENCED_LOSSES => AssessmentAnswerType::BOOLEAN,

            self::SUSTAINABILITY_CONSTRAINTS => AssessmentAnswerType::TEXT,
        };
    }

    /**
     * Options proposées pour les questions à choix (unique ou multiple), valeur brute
     * (telle qu'enregistrée) => libellé. `null` pour les questions à saisie libre.
     *
     * @return array<int|string, string>|null
     */
    public function choices(): ?array
    {
        return match ($this) {
            self::KNOWLEDGE_OPCVM_ETF,
            self::KNOWLEDGE_TITRES_VIFS,
            self::KNOWLEDGE_ASSURANCE_VIE,
            self::KNOWLEDGE_IMMOBILIER_SCPI,
            self::KNOWLEDGE_PRODUITS_COMPLEXES => array_combine(
                array_map(static fn (KnowledgeLevel $case): int => $case->value, KnowledgeLevel::cases()),
                array_map(static fn (KnowledgeLevel $case): string => $case->getLabel(), KnowledgeLevel::cases()),
            ),

            self::EXPERIENCE_PRODUCTS_HELD => array_combine(
                array_map(static fn (ProductFamily $case): string => $case->value, ProductFamily::cases()),
                array_map(static fn (ProductFamily $case): string => $case->getLabel(), ProductFamily::cases()),
            ),

            self::EXPERIENCE_TRANSACTION_FREQUENCY => array_combine(
                array_map(static fn (TransactionFrequency $case): int => $case->value, TransactionFrequency::cases()),
                array_map(static fn (TransactionFrequency $case): string => $case->getLabel(), TransactionFrequency::cases()),
            ),

            self::TOLERANCE_REACTION_MINUS_10,
            self::TOLERANCE_REACTION_MINUS_20,
            self::TOLERANCE_REACTION_SIGNIFICANT_LOSS => array_combine(
                array_map(static fn (LossReaction $case): int => $case->value, LossReaction::cases()),
                array_map(static fn (LossReaction $case): string => $case->getLabel(), LossReaction::cases()),
            ),

            self::CAPACITY_HORIZON => array_combine(
                array_map(static fn (InvestmentHorizon $case): string => $case->value, InvestmentHorizon::cases()),
                array_map(static fn (InvestmentHorizon $case): string => $case->getLabel(), InvestmentHorizon::cases()),
            ),

            self::SUSTAINABILITY_PREFERENCE => array_combine(
                array_map(static fn (SustainabilityPreference $case): string => $case->value, SustainabilityPreference::cases()),
                array_map(static fn (SustainabilityPreference $case): string => $case->getLabel(), SustainabilityPreference::cases()),
            ),

            default => null,
        };
    }

    /**
     * Les questions d'une dimension, dans l'ordre de {@see self::cases()}. Sert à grouper
     * l'assistant client par écran (une dimension = un écran) plutôt qu'une question = un
     * écran, pour raccourcir le parcours perçu sans retirer une seule question.
     *
     * @return list<self>
     */
    public static function forDimension(AssessmentDimension $dimension): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $key): bool => $key->getDimension() === $dimension,
        ));
    }
}
