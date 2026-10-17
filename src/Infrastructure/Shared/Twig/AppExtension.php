<?php

declare(strict_types=1);

namespace App\Infrastructure\Shared\Twig;

use App\Application\Billing\DTO\Response\SubscriptionInfoResponse;
use App\Application\Billing\UseCase\Subscription\GetCurrentSubscriptionUseCase;
use App\Application\Workspace\DTO\Response\WorkspaceInfoResponse;
use App\Application\Workspace\UseCase\CurrentWorkspaceInfo;
use App\Domain\Workspace\Service\CurrentWorkspaceProvider;
use App\Domain\Workspace\Service\SeatAvailability;
use App\Infrastructure\Workspace\Voter\WorkspaceInvitationVoter;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

class AppExtension extends AbstractExtension
{
    public function __construct(
        private readonly GetCurrentSubscriptionUseCase $currentSubscriptionUseCase,
        private readonly CurrentWorkspaceInfo $currentWorkspaceInfo,
        private readonly SeatAvailability $seatAvailability,
        private readonly CurrentWorkspaceProvider $currentWorkspaceProvider,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    #[\Override]
    public function getFilters(): array
    {
        return [
            new TwigFilter('siret', $this->formatSiret(...)),
        ];
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('workspaceInfo', $this->workspaceInfo(...)),
            new TwigFunction('subInfo', $this->subInfo(...)),
            new TwigFunction('seatInfo', $this->seatInfo(...)),
            new TwigFunction('workspace_can', $this->workspaceCan(...)),
        ];
    }

    /**
     * Le collaborateur courant a-t-il ce droit dans son cabinet ?
     * (un administrateur a toujours tout ; sinon, selon la délégation configurée).
     */
    public function workspaceCan(string $capability): bool
    {
        $attribute = match ($capability) {
            'invite' => WorkspaceInvitationVoter::CREATE,
            'edit_cabinet' => WorkspaceInvitationVoter::WORKSPACE_EDIT,
            'manage_portfolio' => WorkspaceInvitationVoter::PORTFOLIO_MANAGE,
            'archive_folder' => WorkspaceInvitationVoter::FOLDER_ARCHIVE,
            // Actions strictement réservées aux administrateurs (non délégables).
            'manage_permissions', 'billing', 'manage_members' => WorkspaceInvitationVoter::PERMISSIONS_MANAGE,
            default => null,
        };

        if (null === $attribute) {
            return false;
        }

        return $this->authorizationChecker->isGranted($attribute, $this->currentWorkspaceProvider->getWorkspace());
    }

    /**
     * @return array{used: int, allowed: int, remaining: int, isFirm: bool}
     */
    public function seatInfo(): array
    {
        $workspace = $this->currentWorkspaceProvider->getWorkspace();

        return [
            'used' => $this->seatAvailability->usedSeats($workspace),
            'allowed' => $this->seatAvailability->allowedSeats($workspace),
            'remaining' => $this->seatAvailability->remainingSeats($workspace),
            'isFirm' => $workspace->isFirm(),
        ];
    }

    public function formatSiret(string $siret): string
    {
        // On nettoie les espaces éventuels et on formate : 3 3 3 5
        $siret = str_replace(' ', '', $siret);
        if (14 !== strlen($siret)) {
            return $siret;
        }

        return sprintf(
            '%s %s %s %s',
            substr($siret, 0, 3),
            substr($siret, 3, 3),
            substr($siret, 6, 3),
            substr($siret, 9, 5)
        );
    }

    public function workspaceInfo(): WorkspaceInfoResponse
    {
        return ($this->currentWorkspaceInfo)();
    }

    public function subInfo(): SubscriptionInfoResponse
    {
        return ($this->currentSubscriptionUseCase)();
    }
}
