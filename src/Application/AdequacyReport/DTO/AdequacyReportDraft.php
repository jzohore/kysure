<?php

declare(strict_types=1);

namespace App\Application\AdequacyReport\DTO;

use App\Domain\AdequacyReport\Entity\ProductAdequacyCheck;
use App\Domain\Compliance\Entity\ComplianceFolder;
use App\Domain\Suitability\Entity\ValidatedInvestorProfile;

/**
 * Brouillon d'un rapport d'adéquation, non persisté : ses éléments sources et le contenu tel
 * qu'il serait figé s'il était validé maintenant.
 */
final readonly class AdequacyReportDraft
{
    /**
     * @param list<ProductAdequacyCheck>                                                                  $checks
     * @param array{acknowledgementSlugId: string, acknowledgedAt: string, statementVersion: string}|null $electronicDeliveryConsent
     * @param array<string, mixed>                                                                        $cabinet
     * @param array<string, mixed>                                                                        $clientSituation
     * @param array<string, mixed>                                                                        $content
     */
    public function __construct(
        public ComplianceFolder $folder,
        public ValidatedInvestorProfile $investorProfile,
        public array $checks,
        public ?array $electronicDeliveryConsent,
        public array $cabinet,
        public array $clientSituation,
        public array $content,
    ) {
    }
}
