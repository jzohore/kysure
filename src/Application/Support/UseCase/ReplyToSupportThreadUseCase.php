<?php

declare(strict_types=1);

namespace App\Application\Support\UseCase;

use App\Domain\Shared\Port\RealTimeNotifierInterface;
use App\Domain\Support\Entity\SupportMessage;
use App\Domain\Support\Entity\SupportThread;
use App\Domain\Support\Enum\SupportSenderType;
use App\Domain\Support\Event\SupportThreadRepliedByAdminEvent;
use App\Domain\Support\Repository\SupportThreadRepositoryInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Use Case : Permet d'ajouter un nouveau message à un fil de discussion existant.
 */
readonly class ReplyToSupportThreadUseCase
{
    // Injection de l'interface (Couche Domaine) et non de l'implémentation Doctrine directe
    public function __construct(
        private SupportThreadRepositoryInterface $threadRepository,
        private RealTimeNotifierInterface $notifier,
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    /**
     * @param SupportThread     $thread     Le ticket concerné
     * @param string            $content    Le contenu du message tapé
     * @param SupportSenderType $senderType Qui envoie le message (Client ou Admin)
     */
    public function execute(SupportThread $thread, string $content, SupportSenderType $senderType): void
    {
        // 1. On demande à la couche Domaine de créer le message.
        // L'entité SupportMessage s'attache elle-même au $thread dans sa méthode write()
        SupportMessage::write($thread, $senderType, $content);

        // 2. On sauvegarde la racine de l'agrégat (SupportThread).
        // Doctrine, grâce à l'option "cascade: ['persist']", va automatiquement
        // détecter le nouveau message et l'insérer dans la table support_messages,
        // tout en mettant à jour le champ updatedAt du SupportThread.
        $this->threadRepository->save($thread);

        $this->notifier->notify(
            topic: 'support_thread_' . $thread->slugId,
            payload: ['action' => 'new_message', 'sender' => $senderType->value]
        );

        $this->notifier->notify(
            topic: 'user_stats_' . $thread->user->slugId,
            payload: ['action' => 'stats_updated']
        );

        // Le client n'est pas forcément sur l'app quand l'admin répond : sans
        // email, il ne le saurait qu'en revenant plus tard voir le badge Mercure.
        if (SupportSenderType::ADMIN === $senderType) {
            $this->eventDispatcher->dispatch(new SupportThreadRepliedByAdminEvent($thread));
        }
    }
}
