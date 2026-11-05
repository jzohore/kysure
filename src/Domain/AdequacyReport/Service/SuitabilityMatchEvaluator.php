<?php

declare(strict_types=1);

namespace App\Domain\AdequacyReport\Service;

use App\Domain\AdequacyReport\Enum\MatchCriterion;
use App\Domain\AdequacyReport\Enum\MatchSeverity;
use App\Domain\AdequacyReport\ValueObject\AdequacyCheckInput;
use App\Domain\AdequacyReport\ValueObject\AdequacyEvaluation;
use App\Domain\AdequacyReport\ValueObject\MatchFinding;
use App\Domain\ClientFile\Enum\AssetClass;
use App\Domain\ClientFile\ValueObject\NetWorthLine;
use App\Domain\Suitability\Enum\KnowledgeLevel;
use App\Domain\Suitability\Enum\ProductFamily;
use App\Domain\Suitability\Enum\SustainabilityPreference;

/**
 * Moteur d'adéquation (lot 3, issue #30) : VÉRIFIE la cohérence d'un produit choisi par le
 * CGP avec la situation du client. Il ne recommande jamais de produit : le conseil reste celui
 * du CGP, KYSURE contrôle et matérialise la justification.
 *
 * Fonction pure, sans état ni dépendance : mêmes entrées → mêmes constats, ce qui rend une
 * vérification figée rejouable et vérifiable a posteriori.
 *
 * ⚠️ Seuils v0 (concentration, réserve de précaution) posés par défaut, à valider avec le CGP
 * pilote — toute évolution doit incrémenter {@see self::VERSION} : la version est figée avec
 * chaque vérification pour qu'un contrôle passé reste explicable.
 */
final class SuitabilityMatchEvaluator
{
    public const string VERSION = 'adequacy-v1';

    /** Au-delà, l'investissement pèse lourd dans le patrimoine net : point d'attention. */
    private const int CONCENTRATION_ATTENTION_PERCENT = 25;

    /** Au-delà, la concentration est jugée bloquante (justification écrite exigée). */
    private const int CONCENTRATION_BLOCKING_PERCENT = 50;

    /** Réserve de précaution attendue après investissement, en mois de charges. */
    private const int PRECAUTION_RESERVE_MONTHS = 6;

    /** Familles difficiles à revendre rapidement (délais de cession, fenêtres de sortie). */
    private const array ILLIQUID_FAMILIES = [ProductFamily::IMMOBILIER_SCPI, ProductFamily::PRODUITS_COMPLEXES];

    public function evaluate(AdequacyCheckInput $input): AdequacyEvaluation
    {
        return new AdequacyEvaluation([
            $this->checkRiskLevel($input),
            $this->checkTargetMarket($input),
            $this->checkHorizon($input),
            $this->checkKnowledgeAndExperience($input),
            $this->checkInvestmentCapacity($input),
            $this->checkConcentration($input),
            $this->checkLiquidity($input),
            $this->checkSustainability($input),
        ]);
    }

    /**
     * SRI du produit (1-7) face au profil retenu (1-7) : un cran au-dessus reste défendable,
     * deux crans ou plus exposent le client à un risque qu'il n'a pas déclaré pouvoir supporter.
     */
    private function checkRiskLevel(AdequacyCheckInput $input): MatchFinding
    {
        $sri = $input->product->sriLevel;
        $profile = $input->retainedProfile;
        $gap = $sri - $profile->value;

        return match (true) {
            $gap <= 0 => new MatchFinding(MatchCriterion::RISK_LEVEL, MatchSeverity::CONFORME, sprintf(
                'Le SRI du produit (%d/7) ne dépasse pas le profil retenu (%d/7 — %s).',
                $sri,
                $profile->value,
                $profile->getLabel(),
            )),
            1 === $gap => new MatchFinding(MatchCriterion::RISK_LEVEL, MatchSeverity::ATTENTION, sprintf(
                'Le SRI du produit (%d/7) dépasse d\'un cran le profil retenu (%d/7 — %s).',
                $sri,
                $profile->value,
                $profile->getLabel(),
            )),
            default => new MatchFinding(MatchCriterion::RISK_LEVEL, MatchSeverity::BLOQUANT, sprintf(
                'Le SRI du produit (%d/7) dépasse de %d crans le profil retenu (%d/7 — %s).',
                $sri,
                $gap,
                $profile->value,
                $profile->getLabel(),
            )),
        };
    }

    private function checkTargetMarket(AdequacyCheckInput $input): MatchFinding
    {
        $profile = $input->retainedProfile;

        if (\in_array($profile, $input->product->targetInvestorProfiles, true)) {
            return new MatchFinding(MatchCriterion::TARGET_MARKET, MatchSeverity::CONFORME, sprintf(
                'Le profil « %s » fait partie du marché cible défini pour ce produit.',
                $profile->getLabel(),
            ));
        }

        return new MatchFinding(MatchCriterion::TARGET_MARKET, MatchSeverity::ATTENTION, sprintf(
            'Le profil « %s » est hors du marché cible défini pour ce produit dans le catalogue.',
            $profile->getLabel(),
        ));
    }

    /**
     * Horizon minimum du produit face à la tranche d'horizon de l'objectif : au-delà de la
     * borne haute, le client devrait sortir avant la durée recommandée.
     */
    private function checkHorizon(AdequacyCheckInput $input): MatchFinding
    {
        $productYears = $input->product->minimumHorizonYears;
        $horizon = $input->objective->horizon;
        $maxYears = $horizon->maxYears();

        if (null !== $maxYears && $productYears > $maxYears) {
            return new MatchFinding(MatchCriterion::HORIZON, MatchSeverity::BLOQUANT, sprintf(
                'L\'horizon minimum du produit (%d ans) dépasse l\'horizon de l\'objectif (%s).',
                $productYears,
                mb_strtolower($horizon->getLabel()),
            ));
        }

        if ($productYears > $horizon->minYears()) {
            return new MatchFinding(MatchCriterion::HORIZON, MatchSeverity::ATTENTION, sprintf(
                'L\'horizon minimum du produit (%d ans) se situe dans la tranche de l\'objectif (%s) : à confirmer avec le client.',
                $productYears,
                mb_strtolower($horizon->getLabel()),
            ));
        }

        return new MatchFinding(MatchCriterion::HORIZON, MatchSeverity::CONFORME, sprintf(
            'L\'horizon minimum du produit (%d ans) est compatible avec l\'horizon de l\'objectif (%s).',
            $productYears,
            mb_strtolower($horizon->getLabel()),
        ));
    }

    /**
     * Produits complexes : une connaissance au moins intermédiaire est exigée. Autres familles :
     * seul le cumul « aucune connaissance + jamais détenu » est signalé.
     */
    private function checkKnowledgeAndExperience(AdequacyCheckInput $input): MatchFinding
    {
        $family = $input->product->family;
        $level = $input->knowledge->levelFor($family);
        $hasHeld = \in_array($family, $input->experience->productsHeld, true);
        $situation = sprintf(
            'connaissance déclarée : %s, %s',
            mb_strtolower($level->getLabel()),
            $hasHeld ? 'déjà détenu' : 'jamais détenu',
        );

        if (ProductFamily::PRODUITS_COMPLEXES === $family) {
            if ($level->value <= KnowledgeLevel::LIMITEE->value) {
                return new MatchFinding(MatchCriterion::KNOWLEDGE_EXPERIENCE, MatchSeverity::BLOQUANT, sprintf(
                    'Produit complexe proposé à un client sans connaissance suffisante (%s).',
                    $situation,
                ));
            }

            if (!$hasHeld) {
                return new MatchFinding(MatchCriterion::KNOWLEDGE_EXPERIENCE, MatchSeverity::ATTENTION, sprintf(
                    'Produit complexe que le client n\'a jamais détenu (%s).',
                    $situation,
                ));
            }
        } elseif (KnowledgeLevel::AUCUNE === $level && !$hasHeld) {
            return new MatchFinding(MatchCriterion::KNOWLEDGE_EXPERIENCE, MatchSeverity::ATTENTION, sprintf(
                'Famille « %s » inconnue du client (%s) : explications à documenter.',
                $family->getLabel(),
                $situation,
            ));
        }

        return new MatchFinding(MatchCriterion::KNOWLEDGE_EXPERIENCE, MatchSeverity::CONFORME, sprintf(
            'Famille « %s » (%s).',
            $family->getLabel(),
            $situation,
        ));
    }

    /**
     * La capacité d'investissement est un jugement du CGP saisi dans l'EER (pas une formule) :
     * la dépasser contredit sa propre analyse.
     */
    private function checkInvestmentCapacity(AdequacyCheckInput $input): MatchFinding
    {
        $capacity = $input->netWorth->investmentCapacityInCents;

        if ($input->amountInCents > $capacity) {
            return new MatchFinding(MatchCriterion::INVESTMENT_CAPACITY, MatchSeverity::BLOQUANT, sprintf(
                'Le montant investi (%s) dépasse la capacité d\'investissement retenue dans la fiche patrimoniale (%s).',
                $this->euros($input->amountInCents),
                $this->euros($capacity),
            ));
        }

        return new MatchFinding(MatchCriterion::INVESTMENT_CAPACITY, MatchSeverity::CONFORME, sprintf(
            'Le montant investi (%s) reste dans la capacité d\'investissement retenue (%s).',
            $this->euros($input->amountInCents),
            $this->euros($capacity),
        ));
    }

    private function checkConcentration(AdequacyCheckInput $input): MatchFinding
    {
        $netWorth = $input->netWorth->netWorth();

        if ($netWorth <= 0) {
            return new MatchFinding(MatchCriterion::CONCENTRATION, MatchSeverity::BLOQUANT, sprintf(
                'Patrimoine net nul ou négatif (%s) : tout investissement concentre l\'exposition du client.',
                $this->euros($netWorth),
            ));
        }

        // Arrondi au pour-cent supérieur : on ne sous-estime jamais une concentration.
        $percent = (int) ceil($input->amountInCents * 100 / $netWorth);
        $message = sprintf(
            'L\'investissement représente %d %% du patrimoine net (%s).',
            $percent,
            $this->euros($netWorth),
        );

        return match (true) {
            $percent > self::CONCENTRATION_BLOCKING_PERCENT => new MatchFinding(MatchCriterion::CONCENTRATION, MatchSeverity::BLOQUANT, $message),
            $percent > self::CONCENTRATION_ATTENTION_PERCENT => new MatchFinding(MatchCriterion::CONCENTRATION, MatchSeverity::ATTENTION, $message),
            default => new MatchFinding(MatchCriterion::CONCENTRATION, MatchSeverity::CONFORME, $message),
        };
    }

    /**
     * Hypothèse prudente : l'investissement est financé sur l'épargne disponible (liquidités +
     * livrets). Si la réserve restante passe sous 6 mois de charges, c'est un point d'attention,
     * bloquant pour un produit difficile à revendre (le client ne pourrait pas faire face à un
     * imprévu sans subir une sortie forcée).
     */
    private function checkLiquidity(AdequacyCheckInput $input): MatchFinding
    {
        $liquidSavings = array_sum(array_map(
            static fn (NetWorthLine $line): int => $line->amountInCents,
            array_filter(
                $input->netWorth->lines,
                static fn (NetWorthLine $line): bool => \in_array($line->assetClass, [AssetClass::AVAILABLE_LIQUIDITY, AssetClass::REGULATED_SAVINGS], true),
            ),
        ));
        $residual = $liquidSavings - $input->amountInCents;
        $reserve = intdiv($input->netWorth->annualExpensesInCents * self::PRECAUTION_RESERVE_MONTHS, 12);
        $isIlliquid = \in_array($input->product->family, self::ILLIQUID_FAMILIES, true);

        if ($residual >= $reserve) {
            return new MatchFinding(MatchCriterion::LIQUIDITY, MatchSeverity::CONFORME, sprintf(
                'Épargne disponible après investissement : %s, au moins %d mois de charges (%s).',
                $this->euros($residual),
                self::PRECAUTION_RESERVE_MONTHS,
                $this->euros($reserve),
            ));
        }

        return new MatchFinding(
            MatchCriterion::LIQUIDITY,
            $isIlliquid ? MatchSeverity::BLOQUANT : MatchSeverity::ATTENTION,
            sprintf(
                'Si l\'investissement est financé sur l\'épargne disponible, il resterait %s, sous la réserve de %d mois de charges (%s)%s.',
                $this->euros($residual),
                self::PRECAUTION_RESERVE_MONTHS,
                $this->euros($reserve),
                $isIlliquid ? ', pour un produit difficile à revendre rapidement' : '',
            ),
        );
    }

    /**
     * Préférence de durabilité (MiFID II) : un produit article 6 ne la satisfait pas. Ce n'est
     * pas bloquant — le client peut adapter sa préférence — mais cela doit être documenté.
     */
    private function checkSustainability(AdequacyCheckInput $input): MatchFinding
    {
        if (SustainabilityPreference::INTERESSE !== $input->sustainabilityPreference) {
            return new MatchFinding(MatchCriterion::SUSTAINABILITY, MatchSeverity::CONFORME, sprintf(
                'Pas de préférence de durabilité à satisfaire (%s).',
                mb_strtolower($input->sustainabilityPreference->getLabel()),
            ));
        }

        $classification = $input->product->sfdrClassification;

        if (!$classification instanceof \App\Domain\ProductCatalogue\Enum\SfdrClassification) {
            return new MatchFinding(MatchCriterion::SUSTAINABILITY, MatchSeverity::ATTENTION, 'Client intéressé par les investissements durables, mais la classification SFDR du produit n\'est pas renseignée dans le catalogue.');
        }

        if (!$classification->promotesSustainability()) {
            return new MatchFinding(MatchCriterion::SUSTAINABILITY, MatchSeverity::ATTENTION, sprintf(
                'Client intéressé par les investissements durables, produit classé %s : documenter que le client accepte d\'adapter sa préférence.',
                mb_lcfirst($classification->getLabel()),
            ));
        }

        return new MatchFinding(MatchCriterion::SUSTAINABILITY, MatchSeverity::CONFORME, sprintf(
            'Client intéressé par les investissements durables, produit classé %s.',
            mb_lcfirst($classification->getLabel()),
        ));
    }

    private function euros(int $cents): string
    {
        return number_format(intdiv($cents, 100), 0, ',', ' ') . ' €';
    }
}
