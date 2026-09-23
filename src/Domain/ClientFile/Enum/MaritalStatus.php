<?php

declare(strict_types=1);

namespace App\Domain\ClientFile\Enum;

enum MaritalStatus: string
{
    case SINGLE = 'single';
    case MARRIED = 'married';
    case PACSED = 'pacsed';
    case COHABITING = 'cohabiting';
    case DIVORCED = 'divorced';
    case WIDOWED = 'widowed';

    public function getLabel(): string
    {
        return match ($this) {
            self::SINGLE => 'Célibataire',
            self::MARRIED => 'Marié(e)',
            self::PACSED => 'Pacsé(e)',
            self::COHABITING => 'Concubinage',
            self::DIVORCED => 'Divorcé(e)',
            self::WIDOWED => 'Veuf/Veuve',
        };
    }
}
