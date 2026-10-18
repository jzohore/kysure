<?php

declare(strict_types=1);

namespace App\Infrastructure\Security\Antivirus;

use App\Domain\Port\VirusScannerInterface;
use Psr\Log\LoggerInterface;

/**
 * Antivirus désactivé (`VIRUS_SCAN_MODE=disabled`) : réservé au développement local
 * et aux tests. Ne jamais l'activer en production.
 */
readonly class NullVirusScanner implements VirusScannerInterface
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    public function scan(string $absolutePath): void
    {
        $this->logger->warning('Antivirus désactivé (VIRUS_SCAN_MODE=disabled) : fichier non analysé.');
    }
}
