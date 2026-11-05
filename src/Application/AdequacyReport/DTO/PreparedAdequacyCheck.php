<?php

declare(strict_types=1);

namespace App\Application\AdequacyReport\DTO;

use App\Domain\AdequacyReport\ValueObject\AdequacyCheckInput;
use App\Domain\ProductCatalogue\Entity\FinancialProduct;
use App\Domain\Suitability\Entity\ValidatedInvestorProfile;

/**
 * Données chargées et vérifiées pour une vérification d'adéquation : les entités sources (pour
 * la traçabilité) et l'entrée typée du moteur.
 */
final readonly class PreparedAdequacyCheck
{
    public function __construct(
        public ValidatedInvestorProfile $investorProfile,
        public FinancialProduct $product,
        public AdequacyCheckInput $input,
    ) {
    }
}
