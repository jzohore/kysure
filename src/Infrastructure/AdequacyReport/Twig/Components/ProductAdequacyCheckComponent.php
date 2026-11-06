<?php

declare(strict_types=1);

namespace App\Infrastructure\AdequacyReport\Twig\Components;

use App\Application\AdequacyReport\DTO\AdequacyCheckPreview;
use App\Application\AdequacyReport\UseCase\EvaluateProductAdequacyUseCase;
use App\Application\AdequacyReport\UseCase\RecordProductAdequacyCheckUseCase;
use App\Domain\AdequacyReport\Entity\ProductAdequacyCheck;
use App\Domain\AdequacyReport\Enum\MatchCriterion;
use App\Domain\AdequacyReport\Enum\MatchSeverity;
use App\Domain\AdequacyReport\Repository\ProductAdequacyCheckRepositoryInterface;
use App\Domain\AdequacyReport\Service\CostBreakdownCalculator;
use App\Domain\AdequacyReport\Service\SuitabilityMatchEvaluator;
use App\Domain\AdequacyReport\ValueObject\CifRemuneration;
use App\Domain\ClientFile\Entity\ClientFile;
use App\Domain\ClientFile\Repository\ClientFileRepositoryInterface;
use App\Domain\ClientFile\ValueObject\NetWorthStatement;
use App\Domain\Compliance\Entity\ComplianceFolder;
use App\Domain\Compliance\Repository\ComplianceFolderRepositoryInterface;
use App\Domain\ProductCatalogue\Entity\FinancialProduct;
use App\Domain\ProductCatalogue\Repository\FinancialProductRepositoryInterface;
use App\Domain\Suitability\Entity\ValidatedInvestorProfile;
use App\Domain\Suitability\Repository\ValidatedInvestorProfileRepositoryInterface;
use App\Domain\User\Entity\Client;
use App\Infrastructure\Compliance\Voter\ComplianceFolderVoter;
use App\Infrastructure\Shared\Component\LiveFlashTrait;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Webmozart\Assert\Assert;

/**
 * Vérification d'adéquation d'un produit (lot 3, issue #30), sur la page dossier. Le CGP choisit
 * produit, objectif et montant (et sa rémunération) : les constats du moteur et les frais
 * (lot 4) s'affichent en direct (lecture seule), puis il enregistre la vérification — figée et
 * tracée en audit. Un point bloquant exige une justification écrite avant l'enregistrement.
 */
#[AsLiveComponent(
    name: 'ProductAdequacyCheckComponent',
    template: 'components/AdequacyReport/ProductAdequacyCheckComponent.html.twig',
)]
class ProductAdequacyCheckComponent extends AbstractController
{
    use DefaultActionTrait;
    use LiveFlashTrait;

    private const int EUROS_TO_CENTS = 100;

    #[LiveProp]
    public string $folderSlugId;

    #[LiveProp(writable: true)]
    public string $productSlugId = '';

    #[LiveProp(writable: true)]
    public string $objectiveIndex = '';

    #[LiveProp(writable: true)]
    public ?int $amountEuros = null;

    /** Rémunération du CIF (lot 4) : pourcentages saisis en texte, convertis en points de base. */
    #[LiveProp(writable: true)]
    public string $entryFeePercent = '';

    #[LiveProp(writable: true)]
    public ?int $advisoryFeeEuros = null;

    #[LiveProp(writable: true)]
    public string $retrocessionPercent = '';

    #[LiveProp(writable: true)]
    public string $justification = '';

    private ?ComplianceFolder $folderCache = null;

    private ?string $evaluationError = null;

    public function __construct(
        private readonly ComplianceFolderRepositoryInterface $folderRepository,
        private readonly ValidatedInvestorProfileRepositoryInterface $profileRepository,
        private readonly ClientFileRepositoryInterface $clientFileRepository,
        private readonly FinancialProductRepositoryInterface $productRepository,
        private readonly ProductAdequacyCheckRepositoryInterface $checkRepository,
        private readonly EvaluateProductAdequacyUseCase $evaluateUseCase,
        private readonly RecordProductAdequacyCheckUseCase $recordUseCase,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getFolder(): ComplianceFolder
    {
        if (!$this->folderCache instanceof ComplianceFolder) {
            $folder = $this->folderRepository->findOneBySlugId($this->folderSlugId);
            Assert::notNull($folder, 'Dossier introuvable.');
            // Chaque re-rendu Live est une requête à part entière : on revérifie l'accès.
            $this->denyAccessUnlessGranted(ComplianceFolderVoter::VIEW, $folder);
            $this->folderCache = $folder;
        }

        return $this->folderCache;
    }

    public function canRecord(): bool
    {
        return !$this->getFolder()->isArchived() && $this->isGranted(ComplianceFolderVoter::EDIT, $this->getFolder());
    }

    /**
     * Premier prérequis manquant, sous forme de consigne actionnable — `null` si tout est prêt.
     *
     * @return array{message: string, route: string|null, routeParams: array<string, string>, cta: string|null}|null
     */
    public function getMissingPrerequisite(): ?array
    {
        $folder = $this->getFolder();

        $hasProfile = $folder->client instanceof Client
            && $this->profileRepository->findInForceByClient($folder->client, $folder->workspace) instanceof ValidatedInvestorProfile;
        if (!$hasProfile) {
            return ['message' => 'Aucun profil investisseur validé n\'est en vigueur pour ce client. Validez-le depuis sa fiche client avant de vérifier un produit.', 'route' => null, 'routeParams' => [], 'cta' => null];
        }

        $clientFile = $this->clientFileRepository->findByComplianceFolder($folder);
        if (!$clientFile instanceof ClientFile || !$clientFile->netWorthStatement() instanceof NetWorthStatement || [] === $clientFile->investmentObjectives()) {
            return ['message' => 'La fiche patrimoniale doit comporter la situation patrimoniale et au moins un objectif d\'investissement.', 'route' => 'app_compliance_wealth_profile_edit', 'routeParams' => ['slugId' => $folder->slugId], 'cta' => 'Compléter la fiche patrimoniale'];
        }

        if ([] === $this->getProducts()) {
            return ['message' => 'Le catalogue du cabinet ne contient aucun produit actif.', 'route' => 'app_products_list', 'routeParams' => [], 'cta' => 'Ouvrir le catalogue'];
        }

        return null;
    }

    /**
     * @return list<FinancialProduct>
     */
    public function getProducts(): array
    {
        return array_values(array_filter(
            $this->productRepository->findByWorkspace($this->getFolder()->workspace),
            static fn (FinancialProduct $product): bool => !$product->isArchived(),
        ));
    }

    /**
     * @return array<int, string> index de l'objectif dans la fiche => libellé
     */
    public function getObjectives(): array
    {
        $clientFile = $this->clientFileRepository->findByComplianceFolder($this->getFolder());
        if (!$clientFile instanceof ClientFile) {
            return [];
        }

        $labels = [];
        foreach ($clientFile->investmentObjectives() as $index => $objective) {
            $labels[$index] = sprintf('%s — %s · %s', $objective->type->getLabel(), mb_strtolower($objective->horizon->getLabel()), mb_strtolower($objective->priority->getLabel()));
        }

        return $labels;
    }

    public function isComplete(): bool
    {
        return '' !== $this->productSlugId && '' !== $this->objectiveIndex && null !== $this->amountEuros && $this->amountEuros > 0;
    }

    /**
     * Aperçu des constats et des frais, recalculé à chaque changement de saisie. Rien n'est
     * enregistré ici.
     */
    public function getPreview(): ?AdequacyCheckPreview
    {
        if (!$this->isComplete()) {
            return null;
        }

        try {
            return ($this->evaluateUseCase)($this->getFolder(), $this->productSlugId, (int) $this->objectiveIndex, $this->amountInCents(), $this->remuneration());
        } catch (\DomainException $e) {
            $this->evaluationError = $e->getMessage();

            return null;
        }
    }

    public function getEvaluationError(): ?string
    {
        return $this->evaluationError;
    }

    /**
     * @return list<ProductAdequacyCheck>
     */
    public function getChecks(): array
    {
        return $this->checkRepository->findByFolder($this->getFolder());
    }

    /**
     * Règles des contrôles, dans l'ordre d'affichage des constats (bulle d'aide).
     *
     * @return list<array{label: string, rule: string}>
     */
    public function getRules(): array
    {
        $descriptions = SuitabilityMatchEvaluator::ruleDescriptions();

        return array_map(
            static fn (MatchCriterion $criterion): array => ['label' => $criterion->getLabel(), 'rule' => $descriptions[$criterion->value]],
            MatchCriterion::cases(),
        );
    }

    public function costsRule(): string
    {
        return CostBreakdownCalculator::ruleDescription();
    }

    /** Centimes → « 1 234,56 € », sans flottant. */
    public function euros(int $cents): string
    {
        return sprintf('%s,%02d €', number_format(intdiv($cents, 100), 0, ',', ' '), $cents % 100);
    }

    /** Points de base → « 1,25 % », sans flottant. */
    public function percent(int $basisPoints): string
    {
        return sprintf('%d,%02d %%', intdiv($basisPoints, 100), $basisPoints % 100);
    }

    public function minJustificationLength(): int
    {
        return ProductAdequacyCheck::MIN_JUSTIFICATION_LENGTH;
    }

    public function severityAccent(MatchSeverity $severity): string
    {
        return match ($severity) {
            MatchSeverity::CONFORME => 'border-l-emerald-500',
            MatchSeverity::ATTENTION => 'border-l-amber-400',
            MatchSeverity::BLOQUANT => 'border-l-rose-500',
        };
    }

    public function severityIcon(MatchSeverity $severity): string
    {
        return match ($severity) {
            MatchSeverity::CONFORME => 'tabler:circle-check',
            MatchSeverity::ATTENTION => 'tabler:alert-triangle',
            MatchSeverity::BLOQUANT => 'tabler:circle-x',
        };
    }

    public function severityIconColor(MatchSeverity $severity): string
    {
        return match ($severity) {
            MatchSeverity::CONFORME => 'text-emerald-600',
            MatchSeverity::ATTENTION => 'text-amber-600',
            MatchSeverity::BLOQUANT => 'text-rose-600',
        };
    }

    #[LiveAction]
    public function record(): void
    {
        $this->clearLiveFlash();
        $this->denyAccessUnlessGranted(ComplianceFolderVoter::EDIT, $this->getFolder());

        if (!$this->isComplete()) {
            $this->addLiveFlash('error', 'Choisissez un produit, un objectif et un montant.');

            return;
        }

        try {
            ($this->recordUseCase)(
                $this->getFolder(),
                $this->productSlugId,
                (int) $this->objectiveIndex,
                $this->amountInCents(),
                $this->remuneration(),
                $this->justification,
            );
            $this->productSlugId = '';
            $this->objectiveIndex = '';
            $this->amountEuros = null;
            $this->entryFeePercent = '';
            $this->advisoryFeeEuros = null;
            $this->retrocessionPercent = '';
            $this->justification = '';
            $this->addLiveFlash('success', 'Vérification d\'adéquation enregistrée.');
        } catch (\DomainException $e) {
            $this->addLiveFlash('error', $e->getMessage());
        } catch (\Throwable $e) {
            $this->logger->error('Crash lors de l\'enregistrement d\'une vérification d\'adéquation.', ['folder_slug_id' => $this->folderSlugId, 'exception' => $e]);
            $this->addLiveFlash('error', 'Erreur système lors de l\'enregistrement.');
        }
    }

    private function amountInCents(): int
    {
        return (int) $this->amountEuros * self::EUROS_TO_CENTS;
    }

    /**
     * @throws \DomainException si un pourcentage saisi est invalide ou les honoraires négatifs
     */
    private function remuneration(): CifRemuneration
    {
        $advisoryFeeEuros = $this->advisoryFeeEuros ?? 0;
        if ($advisoryFeeEuros < 0) {
            throw new \DomainException('Les honoraires de conseil ne peuvent pas être négatifs.');
        }

        return new CifRemuneration(
            entryFeeBasisPoints: CifRemuneration::percentToBasisPoints($this->entryFeePercent),
            advisoryFeeInCents: $advisoryFeeEuros * self::EUROS_TO_CENTS,
            annualRetrocessionBasisPoints: CifRemuneration::percentToBasisPoints($this->retrocessionPercent),
        );
    }
}
