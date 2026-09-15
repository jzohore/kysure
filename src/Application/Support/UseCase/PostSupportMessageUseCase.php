<?php

declare(strict_types=1);

namespace App\Application\Support\UseCase;

use App\Application\Support\Service\SupportAttachmentValidator;
use App\Domain\Port\DocumentStorageInterface;
use App\Domain\Shared\Port\RealTimeNotifierInterface;
use App\Domain\Support\Entity\SupportMessage;
use App\Domain\Support\Entity\SupportThread;
use App\Domain\Support\Enum\SupportCategory;
use App\Domain\Support\Enum\SupportSenderType;
use App\Domain\Support\Enum\SupportTopic;
use App\Domain\Support\Event\SupportThreadCreatedEvent;
use App\Domain\Support\Exception\InvalidSupportAttachmentException;
use App\Domain\Support\Repository\SupportThreadRepositoryInterface;
use App\Domain\User\Entity\User;
use App\Domain\Workspace\Entity\Workspace;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

readonly class PostSupportMessageUseCase
{
    public function __construct(
        private SupportThreadRepositoryInterface $threadRepository,
        private EntityManagerInterface $entityManager,
        private RealTimeNotifierInterface $notifier,
        private EventDispatcherInterface $eventDispatcher,
        private DocumentStorageInterface $documentStorage,
    ) {
    }

    /**
     * @param string      $content    Le message tapé par le client
     * @param string|null $urlContext L'URL où se trouvait le client (pour le contexte)
     *
     * @throws \DateMalformedStringException
     * @throws InvalidSupportAttachmentException
     */
    public function execute(
        Workspace $workspace,
        User $user,
        string $content,
        SupportCategory $category,
        SupportTopic $topic,
        ?string $urlContext = null,
        ?UploadedFile $attachment = null,
    ): void {
        if ($attachment instanceof UploadedFile) {
            SupportAttachmentValidator::assertValid($attachment);
        }

        $managedWorkspace = $this->entityManager->find(Workspace::class, (string) $workspace->id);
        $managedUser = $this->entityManager->find(User::class, (string) $user->id);

        if (!$managedWorkspace || !$managedUser) {
            throw new \LogicException('Erreur critique : Workspace ou User introuvable lors de la création du ticket.');
        }

        // 1. On cherche avec les entités fraîchement managées
        $activeThread = $this->threadRepository->findActiveThreadForUser($managedWorkspace, $managedUser);

        $isNewTicket = false;

        // 1. LAZY CREATION
        if (!$activeThread instanceof SupportThread) {
            $activeThread = SupportThread::open($managedWorkspace, $managedUser, $category->value, $topic->value, $urlContext);
            $isNewTicket = true; // On flag que c'est un tout nouveau ticket
        }

        // 2. Ajout du message du client
        $clientMessage = SupportMessage::write($activeThread, SupportSenderType::CLIENT, $content);

        if ($attachment instanceof UploadedFile) {
            $storagePath = $this->documentStorage->store($attachment, 'support_threads/' . $activeThread->slugId);
            $clientMessage->attachFile($storagePath, $attachment->getClientOriginalName(), (string) $attachment->getMimeType(), (int) $attachment->getSize());
        }

        // 3. 🪄 AUTO-RÉPONSE (Réassurance immédiate)
        // Si c'est un nouveau ticket, on injecte immédiatement un message système pour rassurer le client.

        if ($isNewTicket) {
            $autoReplyContent = sprintf(
                "Bonjour 👋\nNous avons bien reçu votre demande concernant <b>%s (%s)</b>.\nUn expert de l'équipe va prendre le relais et vous répondre très rapidement ici même.",
                $category->getContextLabel(),
                strtolower($topic->getTitle())
            );
            // On écrit ce message en tant qu'ADMIN
            SupportMessage::write($activeThread, SupportSenderType::ADMIN, $autoReplyContent);
        }

        $activeThread->markAsReadByClient();
        // 4. On sauvegarde et on flush
        $this->threadRepository->save($activeThread);

        if ($isNewTicket) {
            $this->eventDispatcher->dispatch(new SupportThreadCreatedEvent($activeThread));
        }

        $this->notifier->notify(
            topic: 'support_thread_' . $activeThread->slugId,
            payload: ['action' => 'new_message', 'sender' => SupportSenderType::CLIENT]
        );
    }
}
