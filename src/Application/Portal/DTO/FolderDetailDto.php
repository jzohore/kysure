<?php

declare(strict_types=1);

namespace App\Application\Portal\DTO;

use App\Domain\User\Enum\ClientPortalStatus;

readonly class FolderDetailDto
{
    public function __construct(
        public string $id,
        public string $title,
        public string $reference,
        public string $openedAtFormatted,
        public ClientPortalStatus $status,
        /** @var array<DocumentItemDto> */
        public array $documents,
        /** Accusé de réception du DER, une fois signé par le client. Null sinon. */
        public ?DerSummaryDto $der = null,
        /** @var list<AdequacyReportSummaryDto> Rapports d'adéquation remis, du plus récent au plus ancien. */
        public array $adequacyReports = [],
    ) {
    }
}
