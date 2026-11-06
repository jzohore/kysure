<?php

declare(strict_types=1);

namespace App\Domain\AdequacyReport\ValueObject;

use Webmozart\Assert\Assert;

/**
 * Rémunération du CIF sur un investissement (lot 4, issue #31), saisie par le CGP sur la
 * vérification : elle n'existe nulle part ailleurs et MIF2 exige qu'elle soit explicite.
 *
 * Cadrage MVP, trois composantes seulement :
 * - droits d'entrée : pourcentage prélevé sur le montant investi, à la souscription ;
 * - honoraires de conseil : montant forfaitaire facturé au client, hors produit ;
 * - rétrocessions : part des frais annuels du produit reversée au CIF. Elles sont COMPRISES
 *   dans les frais du produit (jamais ajoutées au coût total), mais doivent être divulguées.
 *
 * Entiers uniquement : points de base (1 % = 100 pb) et centimes.
 */
final readonly class CifRemuneration
{
    /** 100 % = 10 000 points de base. */
    public const int MAX_BASIS_POINTS = 10_000;

    public function __construct(
        public int $entryFeeBasisPoints = 0,
        public int $advisoryFeeInCents = 0,
        public int $annualRetrocessionBasisPoints = 0,
    ) {
        Assert::range($entryFeeBasisPoints, 0, self::MAX_BASIS_POINTS, 'Les droits d\'entrée doivent être compris entre 0 et 100 %.');
        Assert::greaterThanEq($advisoryFeeInCents, 0, 'Les honoraires de conseil ne peuvent pas être négatifs.');
        Assert::range($annualRetrocessionBasisPoints, 0, self::MAX_BASIS_POINTS, 'Les rétrocessions doivent être comprises entre 0 et 100 %.');
    }

    public static function none(): self
    {
        return new self();
    }

    /**
     * Convertit un pourcentage saisi (« 2 », « 2,5 », « 0.75 ») en points de base, sans passer
     * par un flottant. Une saisie vide vaut 0.
     *
     * @throws \DomainException si la saisie n'est pas un pourcentage à 2 décimales au plus
     */
    public static function percentToBasisPoints(string $percent): int
    {
        $percent = str_replace(' ', '', trim($percent));
        if ('' === $percent) {
            return 0;
        }

        if (1 !== preg_match('/^(\d{1,3})(?:[.,](\d{1,2}))?$/', $percent, $matches)) {
            throw new \DomainException(sprintf('« %s » n\'est pas un pourcentage valide (exemple : 2,5).', $percent));
        }

        $basisPoints = (int) $matches[1] * 100 + (int) str_pad($matches[2] ?? '', 2, '0');
        if ($basisPoints > self::MAX_BASIS_POINTS) {
            throw new \DomainException('Un pourcentage ne peut pas dépasser 100 %.');
        }

        return $basisPoints;
    }

    /**
     * @return array{entryFeeBasisPoints: int, advisoryFeeInCents: int, annualRetrocessionBasisPoints: int}
     */
    public function toArray(): array
    {
        return [
            'entryFeeBasisPoints' => $this->entryFeeBasisPoints,
            'advisoryFeeInCents' => $this->advisoryFeeInCents,
            'annualRetrocessionBasisPoints' => $this->annualRetrocessionBasisPoints,
        ];
    }

    /**
     * @param array{entryFeeBasisPoints: int, advisoryFeeInCents: int, annualRetrocessionBasisPoints: int} $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['entryFeeBasisPoints'], $data['advisoryFeeInCents'], $data['annualRetrocessionBasisPoints']);
    }
}
