<?php

declare(strict_types=1);

namespace App\Infrastructure\Support\Listener;

use App\Domain\Support\Event\SupportThreadRepliedByAdminEvent;
use App\Infrastructure\Support\Message\SendSupportReplyEmailMessage;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Webmozart\Assert\Assert;

#[AsEventListener]
readonly class SendSupportReplyEmailListener
{
    public function __construct(
        private MessageBusInterface $messageBus,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function __invoke(SupportThreadRepliedByAdminEvent $event): void
    {
        $thread = $event->thread;
        Assert::notNull($thread->id);

        $url = $this->urlGenerator->generate('app_support_show', [
            'slugId' => $thread->slugId,
        ], UrlGeneratorInterface::ABSOLUTE_URL);

        $this->messageBus->dispatch(new SendSupportReplyEmailMessage(
            threadId: $thread->id->toString(),
            ticketUrl: $url,
        ));
    }
}
