<?php

declare(strict_types=1);

namespace App\Application\Support\UseCase;

use App\Domain\Support\Entity\SupportThread;
use App\Domain\Support\Event\SupportThreadReopenedEvent;
use App\Domain\Support\Repository\SupportThreadRepositoryInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final readonly class ReopenSupportThreadUseCase
{
    public function __construct(
        private SupportThreadRepositoryInterface $threadRepository,
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function execute(SupportThread $thread): void
    {
        $thread->reopen();
        $this->threadRepository->save($thread);
        $this->eventDispatcher->dispatch(new SupportThreadReopenedEvent($thread));
    }
}
