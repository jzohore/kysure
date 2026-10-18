<?php

declare(strict_types=1);

namespace App\Infrastructure\Security\Antivirus;

use App\Domain\Port\VirusScannerInterface;

readonly class VirusScannerFactory
{
    public function __construct(
        private ClamAvScanner $clamAvScanner,
        private NullVirusScanner $nullVirusScanner,
    ) {
    }

    /**
     * Seule la valeur explicite « disabled » coupe l'analyse : toute autre valeur
     * (faute de frappe, variable absente) retombe sur ClamAV, jamais sur « pas d'antivirus ».
     */
    public function create(string $mode): VirusScannerInterface
    {
        return 'disabled' === $mode ? $this->nullVirusScanner : $this->clamAvScanner;
    }
}
