<?php

declare(strict_types=1);

namespace App\Tests\Domain\AdequacyReport;

use App\Domain\AdequacyReport\Entity\AdequacyReport;
use App\Domain\AdequacyReport\Entity\ProductAdequacyCheck;
use App\Domain\AdequacyReport\Service\CostBreakdownCalculator;
use App\Domain\AdequacyReport\Service\SuitabilityMatchEvaluator;
use App\Domain\AdequacyReport\ValueObject\CifRemuneration;
use App\Domain\Compliance\Entity\ComplianceFolder;
use App\Domain\Compliance\Entity\IndividualFolder;
use App\Domain\Compliance\ValueObject\DerStatement;
use App\Domain\Firm\Entity\RegulatoryProfile;
use App\Domain\ProductCatalogue\Entity\FinancialProduct;
use App\Domain\Suitability\Entity\ValidatedInvestorProfile;
use App\Domain\User\Entity\Client;
use App\Domain\User\Entity\User;
use App\Domain\Workspace\Entity\Workspace;
use App\Tests\Application\ReflectionHelperTrait;
use Symfony\Component\Uid\Uuid;

/**
 * Jeu de données commun aux tests du rapport d'adéquation (lot 5) : un cabinet, un client, son
 * dossier, son profil investisseur en vigueur et des vérifications d'adéquation réelles.
 */
trait AdequacyReportFixturesTrait
{
    use ReflectionHelperTrait;

    private Workspace $workspace;
    private Client $client;
    private ComplianceFolder $folder;
    private User $cgp;
    private ValidatedInvestorProfile $profile;

    private function setUpAdequacyFixtures(): void
    {
        $this->workspace = $this->createEntityState(Workspace::class, ['id' => Uuid::v7(), 'slugId' => 'wrk_1', 'name' => 'Cabinet Horizon', 'siret' => '12345678900011']);
        $this->workspace->legalName = 'Horizon Patrimoine SAS';
        new \ReflectionProperty(Workspace::class, 'regulatoryProfile')->setValue($this->workspace, $this->createEntityState(RegulatoryProfile::class, ['workspace' => $this->workspace, 'oriasNumber' => '12345678']));
        $this->client = $this->createEntityState(Client::class, ['id' => Uuid::v7(), 'slugId' => 'cli_1', 'email' => 'jean@example.com', 'firstName' => 'Jean', 'lastName' => 'Dupont']);
        $this->folder = $this->createEntityState(IndividualFolder::class, ['slugId' => 'fld_1', 'reference' => 'KYC-2026-001', 'workspace' => $this->workspace, 'client' => $this->client]);
        $this->cgp = $this->createEntityState(User::class, ['id' => Uuid::v7(), 'firstName' => 'Marie', 'lastName' => 'Curie']);
        $this->profile = $this->profileState(version: 1);
    }

    private function profileState(int $version, ?\DateTimeImmutable $revokedAt = null): ValidatedInvestorProfile
    {
        return $this->createEntityState(ValidatedInvestorProfile::class, [
            'slugId' => 'vip_' . $version,
            'workspace' => $this->workspace,
            'client' => $this->client,
            'version' => $version,
            'content' => ['answers' => [], 'scoreSnapshot' => ['finalProfile' => 4]],
            'contentHash' => str_repeat('a', 64),
            'validatedAt' => new \DateTimeImmutable('2026-10-01 10:00'),
            'validatedByName' => 'Marie CURIE',
            'revokedAt' => $revokedAt,
        ]);
    }

    private function check(?ValidatedInvestorProfile $profile = null, ?ComplianceFolder $folder = null, ?string $justification = null, int $sriLevel = 4): ProductAdequacyCheck
    {
        $input = AdequacyCheckInputFactory::create(sriLevel: $sriLevel);

        return ProductAdequacyCheck::record(
            complianceFolder: $folder ?? $this->folder,
            investorProfile: $profile ?? $this->profile,
            product: $this->createEntityState(FinancialProduct::class, ['workspace' => $this->workspace, 'name' => 'ETF MSCI World']),
            checkedBy: $this->cgp,
            input: $input,
            evaluation: new SuitabilityMatchEvaluator()->evaluate($input),
            costs: new CostBreakdownCalculator()->calculate($input->product, $input->amountInCents, new CifRemuneration(200, 50_000, 10)),
            justification: $justification,
        );
    }

    /** Vérification enregistrée avant le lot 4 : pas de frais figés. */
    private function legacyCheck(): ProductAdequacyCheck
    {
        $check = $this->check();
        $content = $check->content;
        unset($content['costs']);
        $reflection = new \ReflectionClass(ProductAdequacyCheck::class);
        $reflection->getProperty('content')->setValue($check, $content);
        $reflection->getProperty('contentHash')->setValue($check, hash('sha256', json_encode($content, \JSON_THROW_ON_ERROR)));

        return $check;
    }

    /**
     * @param list<ProductAdequacyCheck>|null $checks
     */
    /**
     * @param array<string, mixed> $cabinetOverrides
     */
    private function validateReport(?array $checks = null, string $rationale = 'Le contrat répond à l\'objectif de préparation de la retraite, avec un risque cohérent avec le profil équilibré.', int $version = 1, ?bool $periodicReviewRequired = true, array $cabinetOverrides = [], bool $withElectronicDeliveryConsent = true): AdequacyReport
    {
        return AdequacyReport::validate(
            folder: $this->folder,
            investorProfile: $this->profile,
            checks: $checks ?? [$this->check()],
            validatedBy: $this->cgp,
            advisorRationale: $rationale,
            periodicReviewRequired: $periodicReviewRequired,
            electronicDeliveryConsent: $withElectronicDeliveryConsent ? ['acknowledgementSlugId' => 'der_ack_1', 'acknowledgedAt' => '2026-11-01T10:00:00+00:00', 'statementVersion' => DerStatement::VERSION] : null,
            cabinet: [...['name' => 'Cabinet Horizon', 'legalName' => 'Horizon Patrimoine SAS', 'address' => '1 rue de la Paix, Paris', 'siret' => '12345678900011', 'oriasNumber' => '12345678', 'professionalAssociation' => null, 'logoStoragePath' => null], ...$cabinetOverrides],
            clientSituation: [
                'situation' => ['civility' => 'Monsieur', 'birthDate' => '1979-04-12', 'maritalStatus' => 'Marié(e)', 'childrenCount' => 2, 'professionalStatus' => 'Salarié', 'profession' => 'Ingénieur'],
                'netWorth' => ['lines' => [['label' => 'Liquidités', 'amountInCents' => 5_000_000]], 'grossWorthInCents' => 5_000_000, 'outstandingDebtInCents' => 0, 'netWorthInCents' => 5_000_000, 'annualIncomeInCents' => 9_600_000, 'annualExpensesInCents' => 3_800_000, 'investmentCapacityInCents' => 3_500_000],
                'objectives' => [['label' => 'Préparation de la retraite', 'priorityLabel' => 'Prioritaire', 'horizonLabel' => '5 à 8 ans', 'amountInCents' => 2_000_000]],
            ],
            version: $version,
        );
    }
}
