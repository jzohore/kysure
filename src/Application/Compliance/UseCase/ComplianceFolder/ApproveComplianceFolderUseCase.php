<?php

declare(strict_types=1);

namespace App\Application\Compliance\UseCase\ComplianceFolder;

use App\Domain\Compliance\Entity\ComplianceFolder;
use App\Domain\Compliance\Enum\RiskLevel;
use App\Domain\Compliance\Event\ComplianceFolderApprovedEvent;
use App\Domain\Compliance\Exception\FolderStateException;
use App\Domain\Compliance\Repository\ComplianceFolderRepositoryInterface;
use App\Domain\Database\TransactionManagerInterface;
use App\Domain\Suitability\Entity\ValidatedInvestorProfile;
use App\Domain\Suitability\Repository\ValidatedInvestorProfileRepositoryInterface;
use App\Domain\User\Entity\Client;
use App\Domain\Workspace\Service\CurrentUserProvider;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Déclare un dossier conforme (LCB-FT). Décision lot 5 : chaque CIF a son propre devoir
 * d'évaluation de l'adéquation, un dossier ne peut donc être approuvé sans qu'un profil
 * investisseur validé soit en vigueur pour ce client auprès de CE cabinet précisément — jamais
 * hérité d'un autre cabinet ({@see Client::$workspaces}).
 *
 * L'autorisation relève d'un voter au niveau du contrôleur/composant ; ce use case se limite
 * aux règles métier.
 */
final readonly class ApproveComplianceFolderUseCase
{
    public function __construct(
        private ComplianceFolderRepositoryInterface $folderRepository,
        private ValidatedInvestorProfileRepositoryInterface $profileRepository,
        private TransactionManagerInterface $transactionManager,
        private CurrentUserProvider $userProvider,
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function __invoke(ComplianceFolder $folder, RiskLevel $riskLevel, string $comments = ''): void
    {
        if (!$folder->client instanceof Client
            || !$this->profileRepository->findInForceByClient($folder->client, $folder->workspace) instanceof ValidatedInvestorProfile
        ) {
            throw FolderStateException::missingValidatedInvestorProfile($folder->reference);
        }

        $user = $this->userProvider->getUser();

        $this->transactionManager->transactional(function () use ($folder, $riskLevel, $comments, $user): void {
            $folder->approve($riskLevel, $user, $comments);
            $this->folderRepository->save($folder);
        });

        $this->eventDispatcher->dispatch(new ComplianceFolderApprovedEvent(
            folderSlugId: $folder->slugId,
            riskLevel: $riskLevel->value,
            approvedByName: $user->getFullName(),
        ));
    }
}
