<?php

declare(strict_types=1);

namespace App\Infrastructure\Ocr\Exception;

/**
 * Échec TECHNIQUE du service d'extraction local (injoignable, timeout, 5xx, réponse invalide).
 *
 * Volontairement distincte de {@see \DomainException} : le contrat de `OcrProviderInterface` réserve
 * `\DomainException` aux documents illisibles (définitif, pas de retry). Celle-ci doit au contraire
 * remonter jusqu'à Messenger, qui réessaie puis laisse `MarkOcrUnavailableOnFailureListener` signaler la pièce.
 */
final class OcrServiceUnavailableException extends \RuntimeException
{
}
