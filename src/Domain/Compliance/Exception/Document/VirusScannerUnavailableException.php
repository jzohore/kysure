<?php

declare(strict_types=1);

namespace App\Domain\Compliance\Exception\Document;

/**
 * L'analyse antivirus n'a pas pu être menée à bien. Par prudence, le fichier est
 * refusé : aucun fichier non analysé n'entre dans le dossier d'un client.
 * Le détail technique reste dans l'exception précédente (logs), jamais côté client.
 */
final class VirusScannerUnavailableException extends \DomainException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct(
            'L\'analyse de sécurité est momentanément indisponible. Merci de réessayer dans quelques instants.',
            0,
            $previous,
        );
    }
}
