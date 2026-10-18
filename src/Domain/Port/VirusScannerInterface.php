<?php

declare(strict_types=1);

namespace App\Domain\Port;

use App\Domain\Compliance\Exception\Document\MalwareDetectedException;
use App\Domain\Compliance\Exception\Document\VirusScannerUnavailableException;

/**
 * Analyse antivirus d'un fichier déposé par un tiers (client final) avant tout stockage.
 */
interface VirusScannerInterface
{
    /**
     * Retourne normalement si le fichier est sain.
     *
     * @param string $absolutePath chemin local du fichier à analyser
     *
     * @throws MalwareDetectedException         le fichier est infecté
     * @throws VirusScannerUnavailableException l'analyse n'a pas pu avoir lieu (on refuse le fichier : fail-closed)
     */
    public function scan(string $absolutePath): void;
}
