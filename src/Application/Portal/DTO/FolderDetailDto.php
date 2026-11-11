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
        /** Formatée par Twig (filtre `date`), qui applique le fuseau de l'application. */
        public \DateTimeImmutable $openedAt,
        public ClientPortalStatus $status,
        /** @var array<DocumentItemDto> */
        public array $documents,
        /** Accusé de réception du DER, une fois signé par le client. Null sinon. */
        public ?DerSummaryDto $der = null,
        /** @var list<AdequacyReportSummaryDto> Rapports d'adéquation remis, du plus récent au plus ancien. */
        public array $adequacyReports = [],
        /** Texte de l'avenant au DER à accepter, si le cabinet en a besoin ; `null` sinon. */
        public ?string $electronicDeliveryConsentToGive = null,
    ) {
    }
}
