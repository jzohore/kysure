<?php

declare(strict_types=1);

namespace App\Infrastructure\Support\Notifier;

use App\Domain\Support\Entity\SupportThread;
use App\Domain\Support\Port\SupportSlaBreachNotifierInterface;
use Symfony\Component\Notifier\Bridge\Slack\SlackOptions;
use Symfony\Component\Notifier\ChatterInterface;
use Symfony\Component\Notifier\Message\ChatMessage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Alerte l'équipe KYSURE sur un canal Slack dédié (`support_sla`, distinct du canal des échecs
 * de vérification cabinet) dès qu'un ticket support dépasse son échéance de première réponse
 * sans avoir été traité.
 */
readonly class SupportSlaBreachSlackNotifier implements SupportSlaBreachNotifierInterface
{
    public function __construct(
        private ChatterInterface $chatter,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function alert(SupportThread $thread): void
    {
        $ticketUrl = $this->urlGenerator->generate(
            'admin_support_show',
            ['slugId' => $thread->slugId],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $message = new ChatMessage(sprintf(
            "⏰ *SLA dépassé — Ticket support*\n\n"
            . "• *Cabinet :* %s\n"
            . "• *Priorité :* %s\n"
            . "• *Sujet :* %s\n"
            . "• *Échéance dépassée le :* %s\n"
            . "• *Assigné à :* %s\n\n"
            . '<%s|Ouvrir le ticket>',
            $thread->workspace->name,
            $thread->priority->getLabel(),
            $thread->getTopicTitle(),
            $thread->dueAt->format('d/m/Y H:i'),
            $thread->assignedTo?->getFullName() ?? 'Personne',
            $ticketUrl,
        ));

        $message->transport('support_sla');

        $slackOptions = new SlackOptions()
            ->username('Support KYSURE')
            ->iconEmoji(':alarm_clock:');

        $message->options($slackOptions);

        $this->chatter->send($message);
    }
}
