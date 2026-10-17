<?php

declare(strict_types=1);

namespace App\Domain\Workspace\Service;

use App\Domain\User\Entity\User;
use App\Domain\Workspace\Entity\Workspace;
use App\Domain\Workspace\Repository\WorkspaceMemberRepositoryInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Contracts\Service\ResetInterface;
use Webmozart\Assert\Assert;

class CurrentWorkspaceProvider implements ResetInterface
{
    // Ce cache ne doit vivre QUE le temps d'une seule requête HTTP.
    private ?Workspace $cachedWorkspace = null;
    private ?string $cachedUserId = null;

    public function __construct(
        private readonly WorkspaceMemberRepositoryInterface $workspaceMemberRepository,
        private readonly Security $security,
    ) {
    }

    /**
     * Appelé automatiquement par Symfony à la fin de chaque requête
     * pour nettoyer le service avant qu'il ne serve un autre client.
     */
    public function reset(): void
    {
        $this->cachedWorkspace = null;
        $this->cachedUserId = null;
    }

    public function getWorkspace(): Workspace
    {
        // 1. On récupère TOUJOURS l'utilisateur actuel via la session sécurisée
        $user = $this->security->getUser();
        Assert::notNull($user, 'Utilisateur non connecté.');
        Assert::isInstanceOf($user, User::class, 'Utilisateur invalide.');
        Assert::notNull($user->id);

        $currentUserId = (string) $user->id;

        // 2. 🛡️ SÉCURITÉ MAXIMALE : Vérification du cache
        if ($this->cachedWorkspace instanceof Workspace) {
            // On s'assure que le cache appartient BIEN à l'utilisateur qui fait la requête
            // (Protège contre les fuites mémoire de Swoole/FrankenPHP ou le "Switch User" (Impersonation) de Symfony)
            if ($this->cachedUserId === $currentUserId) {
                return $this->cachedWorkspace;
            }

            // Si l'ID ne correspond pas, c'est qu'on a un cache "sale" (changement d'utilisateur)
            // On purge le cache immédiatement par sécurité.
            $this->cachedWorkspace = null;
            $this->cachedUserId = null;
        }

        // 3. Sinon, on va chercher l'info en base
        $workspaceMember = $this->workspaceMemberRepository->findOneByUser($user->id);
        Assert::notNull($workspaceMember, 'Aucun espace de travail trouvé pour cet utilisateur.');

        $workspace = $workspaceMember->workspace;

        // 4. On remplit le cache sécurisé pour les prochains appels
        $this->cachedWorkspace = $workspace;
        $this->cachedUserId = $currentUserId;

        return $workspace;
    }
}
