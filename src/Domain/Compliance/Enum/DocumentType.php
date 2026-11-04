<?php

declare(strict_types=1);

namespace App\Domain\Compliance\Enum;

enum DocumentType: string
{
    case ID_CARD = 'id_card';
    case DER = 'der';
    case PASSPORT = 'passport';
    case RESIDENCE_PERMIT = 'residence_permit';
    case PROOF_OF_ADDRESS = 'proof_of_address';

    case KBIS = 'kbis';
    case ARTICLES_OF_ASSOC = 'articles_of_assoc';
    case RBE = 'rbe';
    case ORGANIZATION_CHART = 'organization_chart';
    case FINANCIAL_STATEMENTS = 'financial_statements';

    case SOURCE_OF_FUNDS = 'source_of_funds';
    case RIB = 'rib';

    public function getLabel(): string
    {
        return match ($this) {
            self::ID_CARD => 'Carte Nationale d\'Identité (Recto/Verso)',
            self::PASSPORT => 'Passeport (Page biométrique)',
            self::RESIDENCE_PERMIT => 'Titre de séjour en cours de validité',
            self::PROOF_OF_ADDRESS => 'Justificatif de domicile (moins de 3 mois)',
            self::KBIS => 'Extrait K-bis (moins de 3 mois)',
            self::ARTICLES_OF_ASSOC => 'Statuts constitutifs à jour et signés',
            self::RBE => 'Déclaration des Bénéficiaires Effectifs (UBO)',
            self::ORGANIZATION_CHART => 'Organigramme capitalistique daté et signé',
            self::FINANCIAL_STATEMENTS => 'Dernière liasse fiscale / Bilan',
            self::SOURCE_OF_FUNDS => 'Justificatif d\'origine des fonds',
            self::RIB => 'Relevé d\'Identité Bancaire (RIB)',
            self::DER => 'Document d\'entrée en relation',
        };
    }

    /**
     * Regroupement sémantique pour l'affichage UI (Twig) et le moteur de règles.
     */
    public function getCategory(): string
    {
        return match ($this) {
            self::ID_CARD, self::PASSPORT, self::RESIDENCE_PERMIT, self::PROOF_OF_ADDRESS => 'Identité & Domicile',
            self::KBIS, self::ARTICLES_OF_ASSOC, self::RBE, self::ORGANIZATION_CHART, self::FINANCIAL_STATEMENTS => 'Personne Morale',
            self::SOURCE_OF_FUNDS, self::RIB => 'Financier & LCB-FT',
            self::DER => 'Contractuel',
        };
    }

    public function isHighRiskDocument(): bool
    {
        return match ($this) {
            self::SOURCE_OF_FUNDS, self::ORGANIZATION_CHART => true,
            default => false,
        };
    }

    /**
     * Une analyse automatique (OCR + contrôles) existe pour ce type de pièce. Les autres
     * relèvent d'une vérification manuelle : ne pas les envoyer à l'OCR (coût, faux signalements).
     */
    public function supportsOcr(): bool
    {
        return match ($this) {
            self::ID_CARD, self::PASSPORT, self::RESIDENCE_PERMIT, self::PROOF_OF_ADDRESS, self::KBIS, self::RIB => true,
            default => false,
        };
    }

    /**
     * La pièce a deux faces déposées séparément : le recto dans l'emplacement principal, le verso dans un second
     * fichier. Seul le verso de la CNI porte la bande de lecture optique (MRZ) qui sert de second avis à l'analyse.
     */
    public function hasBackSide(): bool
    {
        return self::ID_CARD === $this;
    }

    /**
     * Consigne de prise de vue affichée au client AVANT l'envoi : une pièce lisible du premier coup
     * évite un échec d'analyse automatique puis un aller-retour avec le cabinet. Purement indicatif.
     */
    public function getCaptureTip(): ?string
    {
        return match ($this) {
            self::ID_CARD => 'Déposez le recto, puis le verso dans le second emplacement (ou les deux faces dans un seul PDF, au premier). Les quatre coins doivent être visibles, sans reflet sur la puce ni sur la zone de lecture en bas.',
            self::PASSPORT => 'Photographiez la page avec la photo, bien à plat. Les deux lignes de caractères en bas de page doivent être entièrement visibles.',
            self::RESIDENCE_PERMIT => 'Photographiez le recto et le verso, sans reflet, avec les quatre coins visibles.',
            self::PROOF_OF_ADDRESS => 'Envoyez la page complète, avec votre nom, votre adresse et la date d\'émission bien lisibles.',
            self::KBIS => 'Envoyez l\'extrait complet, de préférence le PDF d\'origine plutôt qu\'une photo d\'écran.',
            self::RIB => 'Envoyez le RIB en entier, avec l\'IBAN et le titulaire bien lisibles.',
            default => null,
        };
    }

    /**
     * Définit si le système doit surveiller la péremption temporelle de cette pièce.
     * Crucial pour le Worker de relance automatique (Event-Driven).
     */
    public function hasExpiration(): bool
    {
        return match ($this) {
            self::ID_CARD, self::PASSPORT, self::RESIDENCE_PERMIT, self::PROOF_OF_ADDRESS, self::KBIS => true,
            default => false,
        };
    }
}
