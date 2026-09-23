<?php

declare(strict_types=1);

namespace App\Application\AdequacyReport\UseCase;

use App\Application\AdequacyReport\DTO\Response\AdequacyReportMockupResponse;
use App\Domain\Workspace\Service\CurrentWorkspaceProvider;

use function Symfony\Component\Clock\now;

/**
 * Lot 0 du chantier "rapport d'adéquation" (feuille de route CIF Pilot phase 2, mémoire
 * cif-pilot-roadmap-phase2) : construit la maquette à données fictives destinée à être validée
 * par écrit par le cabinet pilote — sections, mentions, formulations — avant d'écrire la
 * moindre entité de domaine (EER, catalogue produits, moteur d'adéquation...). Aucune
 * persistance, aucun repository : uniquement l'identité du cabinet connecté (déjà publique
 * ailleurs dans l'app) plus un jeu de données fictives cohérent.
 */
final readonly class BuildAdequacyReportMockupUseCase
{
    public function __construct(
        private CurrentWorkspaceProvider $currentWorkspaceProvider,
    ) {
    }

    public function build(): AdequacyReportMockupResponse
    {
        $workspace = $this->currentWorkspaceProvider->getWorkspace();

        return new AdequacyReportMockupResponse(
            workspaceName: $workspace->name,
            workspaceLegalName: $workspace->legalName,
            workspaceAddress: $workspace->address,
            workspaceSiret: $workspace->siret,
            workspaceLogoStoragePath: $workspace->regulatoryProfile?->logoStoragePath,
            generatedAt: now(),
            clientFullName: 'Marie Duval (client fictif — maquette)',
            clientSituationSummary: 'Cadre supérieure, 47 ans, mariée sous le régime de la communauté, 2 enfants majeurs. '
                . 'Revenus annuels du foyer : 96 000 €. Charges annuelles : 38 000 €.',
            objectives: [
                ['label' => 'Préparation de la retraite', 'priority' => 'Prioritaire', 'amount' => '150 000 €', 'horizon' => '8 à 10 ans'],
                ['label' => 'Valorisation du capital', 'priority' => 'Secondaire', 'amount' => '50 000 €', 'horizon' => '5 à 8 ans'],
                ['label' => 'Optimisation fiscale', 'priority' => 'Secondaire', 'amount' => '—', 'horizon' => '3 à 5 ans'],
            ],
            investorProfileLevel: 4,
            investorProfileLabel: 'Équilibré',
            netWorthLines: [
                ['label' => 'Résidence principale (net de crédit)', 'amount' => '180 000 €'],
                ['label' => 'Assurance-vie', 'amount' => '65 000 €'],
                ['label' => 'PEA', 'amount' => '22 000 €'],
                ['label' => 'Compte-titres', 'amount' => '8 000 €'],
                ['label' => 'Livrets / épargne réglementée', 'amount' => '15 000 €'],
                ['label' => 'Liquidités disponibles', 'amount' => '12 000 €'],
            ],
            netWorthGross: '302 000 €',
            netWorthNet: '278 000 €',
            investmentCapacity: '35 000 €',
            productName: 'Contrat d\'assurance-vie multisupport — fonds euros + UC diversifiées',
            productProducer: 'Assureur partenaire (fictif)',
            productIsin: 'FR0000999999',
            productTypology: 'Assurance-vie, support en unités de compte',
            productSri: 3,
            productRecommendedHorizon: '8 ans minimum',
            productMinInvestment: '10 000 €',
            productReturnObjective: '3 à 4 % annualisé (non garanti)',
            adequacyJustification: 'Le produit correspond à l\'objectif de préparation de la retraite (horizon 8-10 ans compatible '
                . 'avec l\'horizon recommandé), au profil investisseur équilibré (SRI 3, cohérent avec le niveau 4/7), et à la '
                . 'capacité d\'investissement disponible (35 000 € de capacité pour un minimum de souscription de 10 000 €). '
                . 'La cliente dispose d\'une connaissance intermédiaire de l\'assurance-vie et d\'une expérience antérieure sur '
                . 'ce type de support. Aucune préférence de durabilité contraignante déclarée.',
            cifRemuneration: 'Rémunération initiale : 1,5 % du montant investi, versée par le producteur. Rémunération récurrente : 0,4 % / an sur l\'encours.',
            feeLines: [
                ['label' => 'Frais de souscription', 'amount' => '2,0 % soit 700 €', 'note' => 'dont 1,5 % (525 €) au titre de la rémunération du CIF'],
                ['label' => 'Frais de gestion annuels', 'amount' => '0,85 % / an', 'note' => 'prélevés sur l\'encours, impact cumulé estimé sur 8 ans : environ 2 380 €'],
                ['label' => 'Frais de sortie', 'amount' => 'Aucun', 'note' => 'au-delà de 4 ans de détention'],
                ['label' => 'Autres frais (arbitrage UC)', 'amount' => '0,5 % par arbitrage', 'note' => 'facultatif, à la demande du client uniquement'],
            ],
            totalFeesImpact: 'Sur un horizon de 8 ans et un investissement de 35 000 €, les frais cumulés estimés représentent environ 3 780 €, soit 10,8 % du montant investi.',
            scenarios: [
                ['name' => 'Défavorable', 'hypothesis' => '-2 % / an', 'finalValue' => '27 900 €', 'cumulativeFees' => '3 100 €', 'netReturn' => '-2,8 % / an'],
                ['name' => 'Cible', 'hypothesis' => '+3,5 % / an', 'finalValue' => '42 300 €', 'cumulativeFees' => '3 780 €', 'netReturn' => '+2,4 % / an'],
                ['name' => 'Favorable', 'hypothesis' => '+6 % / an', 'finalValue' => '51 800 €', 'cumulativeFees' => '4 250 €', 'netReturn' => '+4,6 % / an'],
            ],
        );
    }
}
