<?php

declare(strict_types=1);

namespace App\Infrastructure\Support\Twig\Components;

use App\Application\Support\UseCase\MarkMessagesAsReadUseCase;
use App\Application\Support\UseCase\ReplyToSupportThreadUseCase;
use App\Domain\Support\Entity\SupportThread;
use App\Domain\Support\Enum\SupportSenderType;
use App\Domain\Support\Repository\SupportThreadRepositoryInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\LiveComponent\LiveResponder;

#[AsLiveComponent(
    name: 'AdminSupportChatComponent',
    template: 'components/Support/AdminSupportChatComponent.html.twig',
    route: 'admin_ux_live_component',
)]
class AdminSupportChatComponent
{
    use DefaultActionTrait;

    // L'entité cible est injectée depuis le template parent
    #[LiveProp]
    public SupportThread $thread;

    #[LiveProp(writable: true)]
    public string $message = '';

    public function __construct(
        private readonly ReplyToSupportThreadUseCase $replyUseCase,
        private readonly MarkMessagesAsReadUseCase $markAsReadUseCase,
        private readonly SupportThreadRepositoryInterface $supportThreadRepository,
    ) {
    }

    public function mount(SupportThread $thread): void
    {
        $this->thread = $thread;
    }

    /**
     * S'exécute AVANT chaque rendu du template.
     * C'est ici qu'on garantit que l'admin voit tout et marque tout comme lu.
     */
    #[LiveAction]
    public function refresh(): void
    {
        // On force Doctrine à aller chercher les nouveaux messages en base
        $this->supportThreadRepository->refresh($this->thread);

        // On marque comme lu
        $this->markAsReadUseCase->execute($this->thread, SupportSenderType::ADMIN);
    }

    #[LiveAction]
    public function sendMessage(LiveResponder $responder): void
    {
        if (in_array(trim($this->message), ['', '0'], true)) {
            return;
        }

        $this->replyUseCase->execute($this->thread, $this->message, SupportSenderType::ADMIN);
        $this->message = ''; // On vide le champ après l'envoi

        // Optionnel : Émettre un événement global si tu as un composant "Badge" côté Admin
        $responder->emit('support_updated');
    }
}
