<?php

declare(strict_types=1);

namespace App\Domain\Compliance\Enum;

/**
 * Canal par lequel un dossier de conformité a été ouvert (colonne
 * `creation_method`). Les valeurs sont persistées : ne jamais les renommer.
 */
enum FolderCreationMethod: string
{
    /** Ouvert par le cabinet depuis « Nouveau dossier ». */
    case CABINET = 'request';

    /** Ouvert depuis la fiche d'un client du portefeuille. */
    case CLIENT_PAGE = 'from_client_page';

    /** Ouvert par le client lui-même via le QR code public du cabinet. */
    case FLASH_QR_CODE = 'flash_qr_code';
}
