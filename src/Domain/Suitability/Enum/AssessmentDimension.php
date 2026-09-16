<?php

declare(strict_types=1);

namespace App\Domain\Suitability\Enum;

/**
 * Les 5 dimensions du questionnaire (§3 du cahier des charges), utilisées pour grouper les
 * {@see QuestionKey} en étapes côté assistant client.
 */
enum AssessmentDimension: string
{
    case KNOWLEDGE = 'knowledge';
    case EXPERIENCE = 'experience';
    case TOLERANCE = 'tolerance';
    case CAPACITY = 'capacity';
    case SUSTAINABILITY = 'sustainability';

    public function getLabel(): string
    {
        return match ($this) {
            self::KNOWLEDGE => 'Connaissances financières',
            self::EXPERIENCE => 'Expérience d\'investissement',
            self::TOLERANCE => 'Tolérance au risque',
            self::CAPACITY => 'Situation financière',
            self::SUSTAINABILITY => 'Préférences de durabilité',
        };
    }
}
