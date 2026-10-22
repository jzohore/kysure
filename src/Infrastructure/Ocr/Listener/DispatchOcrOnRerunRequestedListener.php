<?php

declare(strict_types=1);

namespace App\Infrastructure\Ocr\Listener;

use App\Domain\Compliance\Event\DocumentOcrRerunRequestedEvent;
use App\Infrastructure\Ocr\Message\ProcessOcrMessage;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\MessageBusInterface;
use Webmozart\Assert\Assert;

/**
 * Met l'analyse en file (`async_heavy`) quand un opérateur la relance. Si la file est injoignable, l'exception
 * remonte volontairement au contrôleur : l'opérateur doit savoir que rien n'est parti (contrairement au dépôt
 * client, qui ne doit jamais échouer pour cette raison).
 *
 * Idempotent par construction : le handler ne réanalyse pas une pièce déjà analysée, et l'entité refuse une
 * seconde relance tant que la première est en cours.
 */
#[AsEventListener]
readonly class DispatchOcrOnRerunRequestedListener
{
    public function __construct(
        private MessageBusInterface $messageBus,
    ) {
    }

    public function __invoke(DocumentOcrRerunRequestedEvent $event): void
    {
        $id = $event->document->id;
        Assert::notNull($id, 'La pièce doit être enregistrée avant sa relance.');

        $this->messageBus->dispatch(new ProcessOcrMessage($id->toString()));
    }
}
