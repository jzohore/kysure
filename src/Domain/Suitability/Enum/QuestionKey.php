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
}
