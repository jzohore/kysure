<?php

declare(strict_types=1);

namespace App\Infrastructure\Support\Twig\Components;

use App\Application\Support\DTO\Response\SupportNotificationStats;
use App\Application\Support\UseCase\PostSupportMessageUseCase;
use App\Application\Support\UseCase\SupportNotificationStatsUseCase;
use App\Domain\Support\Entity\SupportThread;
use App\Domain\Support\Enum\SupportCategory;
use App\Domain\Support\Enum\SupportTopic;
use App\Domain\Support\Repository\SupportThreadRepositoryInterface;
use App\Domain\User\Repository\UserRepositoryInterface;
use App\Domain\Workspace\Service\CurrentWorkspaceProvider;
use Symfony\Component\Uid\Uuid;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\LiveComponent\LiveResponder;

#[AsLiveComponent(
    name: 'SupportChatComponent',
    template: 'components/Support/SupportChatComponent.html.twig'
)]
final class SupportChatComponent
{
    use ComponentToolsTrait;
    use DefaultActionTrait;

    /**
     * Le contenu tapé par l'utilisateur.
     * writable: true permet au composant de se mettre à jour quand l'utilisateur tape.
     */
    #[LiveProp(writable: true)]
    public string $message = '';

    /**
     * Le contexte de l'URL d'où la conversation a été ouverte.
     */
    #[LiveProp]
    public ?string $urlContext = null;

    #[LiveProp]
    public Uuid $userUuid;

    #[LiveProp]
    public int $step = 1;

    #[LiveProp]
    public ?SupportCategory $category = null;

    #[LiveProp]
    public ?SupportTopic $topic = null;

    public function __construct(
        private readonly PostSupportMessageUseCase $postMessageUseCase,
        private readonly SupportThreadRepositoryInterface $threadRepository,
        private readonly CurrentWorkspaceProvider $workspaceProvider,
        private readonly UserRepositoryInterface $userRepository,
        private readonly SupportNotificationStatsUseCase $notificationStatsUseCase,
    ) {
    }

    /**
     * Getter calculé : Récupère la conversation en cours à chaque rendu du composant.
     * C'est très performant car Doctrine mettra la requête en cache interne.
     */
    public function getThread(): ?SupportThread
    {
        $user = $this->userRepository->getById($this->userUuid);
        $workspace = $this->workspaceProvider->getWorkspace();

        return $this->threadRepository->findActiveThreadForUser(
            $workspace,
            $user
        );
    }

    /**
     * @return SupportCategory[]
     */
    public function getCategories(): array
    {
        return SupportCategory::cases();
    }

    /**
     * @return SupportTopic[]
     */
    public function getTopics(): array
    {
        if (!$this->category instanceof SupportCategory) {
            return [];
        }

        // 🛡️ DDD : On demande à l'Enum (Domaine) de nous donner ses règles métier.
        return $this->category->getAllowedTopics();
    }

    // --- ACTIONS UTILISATEUR (LIVE ACTIONS) ---

    #[LiveAction]
    public function chooseCategory(#[LiveArg] SupportCategory $category): void
    {
        $this->category = $category;
        $this->step = 2; // Avance à la sélection du sous-sujet
    }

    #[LiveAction]
    public function chooseTopic(#[LiveArg] SupportTopic $topic): void
    {
        $this->topic = $topic;
        $this->step = 3; // Ouvre la fenêtre de chat
    }

    #[LiveAction]
    public function goBack(): void
    {
        $this->step = max(1, $this->step - 1);
    }

    #[LiveAction]
    public function resetWizard(): void
    {
        $this->step = 1;
        $this->category = null;
        $this->topic = null;
    }

    #[LiveAction]
    public function refresh(): void
    {
        // Cette méthode vide suffit à déclencher un cycle de rendu.
        // Le getThread() sera ré-exécuté, récupérant les nouveaux messages en base.
    }

    public function getStats(): SupportNotificationStats
    {
        return ($this->notificationStatsUseCase)();
    }

    /**
     * Action déclenchée depuis le Twig (soumission du formulaire ou touche Entrée).
     */
    #[LiveAction]
    public function sendMessage(LiveResponder $responder): void
    {
        // Sécurité de base
        if (in_array(trim($this->message), ['', '0'], true)) {
            return;
        }
        $user = $this->userRepository->getById($this->userUuid);
        $workspace = $this->workspaceProvider->getWorkspace();

        // 🚀 On exécute la logique métier via notre Use Case existant !
        $this->postMessageUseCase->execute(
            $workspace,
            $user,
            $this->message,
            $this->category ?? SupportCategory::OTHER,
            $this->topic ?? SupportTopic::OTHER,
            $this->urlContext
        );

        // On vide le champ de texte après l'envoi
        $this->message = '';
        $responder->emit('message_sent');
    }
}
