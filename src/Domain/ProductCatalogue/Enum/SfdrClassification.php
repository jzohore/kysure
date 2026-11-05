<?php

declare(strict_types=1);

namespace App\Domain\ProductCatalogue\Enum;

/**
 * Classification du produit au sens du règlement SFDR (UE 2019/2088), lue dans la documentation
 * précontractuelle du produit. Sert à confronter le produit à la préférence de durabilité du
 * client (MiFID II, règlement délégué 2021/1253) dans le moteur d'adéquation (lot 3).
 *
 * Simplification v0 volontaire : on ne modélise ni la part minimale d'investissements durables
 * ni l'alignement taxonomie — l'article SFDR suffit à signaler une incohérence au CGP.
 */
enum SfdrClassification: string
{
    case ARTICLE_6 = 'article_6';
    case ARTICLE_8 = 'article_8';
    case ARTICLE_9 = 'article_9';

    public function getLabel(): string
    {
        return match ($this) {
            self::ARTICLE_6 => 'Article 6 — sans objectif de durabilité',
            self::ARTICLE_8 => 'Article 8 — promeut des caractéristiques environnementales ou sociales',
            self::ARTICLE_9 => 'Article 9 — objectif d\'investissement durable',
        };
    }

    public function promotesSustainability(): bool
    {
        return self::ARTICLE_6 !== $this;
    }
}
