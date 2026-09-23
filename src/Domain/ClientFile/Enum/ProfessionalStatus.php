<?php

declare(strict_types=1);

namespace App\Domain\ClientFile\Enum;

enum ProfessionalStatus: string
{
    case SALARIED = 'salaried';
    case SELF_EMPLOYED = 'self_employed';
    case EXECUTIVE = 'executive';
    case RETIRED = 'retired';
    case UNEMPLOYED = 'unemployed';
    case OTHER = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::SALARIED => 'Salarié(e)',
            self::SELF_EMPLOYED => 'Indépendant(e)',
            self::EXECUTIVE => 'Dirigeant(e)',
            self::RETIRED => 'Retraité(e)',
            self::UNEMPLOYED => 'Sans activité',
            self::OTHER => 'Autre',
        };
    }
}
