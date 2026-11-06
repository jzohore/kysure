<?php

declare(strict_types=1);

namespace App\Application\AdequacyReport\UseCase;

use App\Domain\AdequacyReport\Entity\ProductAdequacyCheck;
use App\Domain\AdequacyReport\Event\ProductAdequacyCheckRecordedEvent;
use App\Domain\AdequacyReport\Repository\ProductAdequacyCheckRepositoryInterface;
use App\Domain\AdequacyReport\Service\CostBreakdownCalculator;
use App\Domain\AdequacyReport\Service\SuitabilityMatchEvaluator;
use App\Domain\AdequacyReport\ValueObject\CifRemuneration;
use App\Domain\Compliance\Entity\ComplianceFolder;
use App\Domain\Compliance\Repository\ComplianceFolderRepositoryInterface;
use App\Domain\Database\TransactionManagerInterface;
use App\Domain\Workspace\Service\CurrentUserProvider;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Enregistre une vérification d'adéquation figée. Le moteur et le calcul des frais sont rejoués
 * côté serveur au moment de l'enregistrement (jamais de constats ni de montants calculés reçus
 * du navigateur) : ce qui est scellé est ce qu'ils produisent sur les données en base à cet
 * instant. Seule la rémunération du CIF est une saisie du CGP.
 *
 * L'autorisation (membre du cabinet + dossier visible + droit d'édition) relève du voter,
 * vérifié par l'appelant.
 */
final readonly class RecordProductAdequacyCheckUseCase
{
    public function __construct(
        private PrepareProductAdequacyCheckUseCase $prepare,
        private SuitabilityMatchEvaluator $evaluator,
        private CostBreakdownCalculator $costCalculator,
        private ProductAdequacyCheckRepositoryInterface $checkRepository,
        private ComplianceFolderRepositoryInterface $folderRepository,
        private TransactionManagerInterface $transactionManager,
        private CurrentUserProvider $userProvider,
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function __invoke(
        ComplianceFolder $folder,
        string $productSlugId,
        int $objectiveIndex,
        int $amountInCents,
        CifRemuneration $remuneration,
        ?string $justification,
    ): string {
        if ($folder->isArchived()) {
            throw new \DomainException('Ce dossier est archivé : il est en lecture seule.');
        }

        $prepared = ($this->prepare)($folder, $productSlugId, $objectiveIndex, $amountInCents);
        $evaluation = $this->evaluator->evaluate($prepared->input);
        $costs = $this->costCalculator->calculate($prepared->input->product, $prepared->input->amountInCents, $remuneration);

        $check = ProductAdequacyCheck::record(
            complianceFolder: $folder,
            investorProfile: $prepared->investorProfile,
            product: $prepared->product,
            checkedBy: $this->userProvider->getUser(),
            input: $prepared->input,
            evaluation: $evaluation,
            costs: $costs,
            justification: $justification,
        );

        $folder->saveHistory(
            'Vérification d\'adéquation produit',
            sprintf('%s — %s%s', $check->productNameAtCheck(), $check->worstSeverity->getLabel(), $check->hasBlockingFinding() ? ' (poursuite justifiée par écrit)' : ''),
        );

        $this->transactionManager->transactional(function () use ($check, $folder): void {
            $this->checkRepository->save($check, flush: false);
            $this->folderRepository->save($folder);
        });

        $this->eventDispatcher->dispatch(new ProductAdequacyCheckRecordedEvent(
            checkSlugId: $check->slugId,
            folderSlugId: $folder->slugId,
            productName: $check->productNameAtCheck(),
            amountInCents: $check->amountInCents,
            worstSeverity: $check->worstSeverity->value,
            hasBlockingFinding: $check->hasBlockingFinding(),
            justification: $check->justification,
            checkedByName: $check->checkedByName,
            contentHash: $check->contentHash,
        ));

        return $check->slugId;
    }
}
