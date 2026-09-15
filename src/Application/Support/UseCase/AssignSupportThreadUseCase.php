<?php

declare(strict_types=1);

namespace App\Application\Support\UseCase;

use App\Domain\Support\Entity\SupportThread;
use App\Domain\Support\Event\SupportThreadAssignedEvent;
use App\Domain\Support\Repository\SupportThreadRepositoryInterface;
use App\Domain\User\Entity\Admin;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final readonly class AssignSupportThreadUseCase
{
    public function __construct(
        private SupportThreadRepositoryInterface $threadRepository,
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    /**
     * @param Admin|null $admin null = désassigne le ticket
     */
    public function execute(SupportThread $thread, ?Admin $admin): void
    {
        $thread->assignTo($admin);
        $this->threadRepository->save($thread);
        $this->eventDispatcher->dispatch(new SupportThreadAssignedEvent($thread, $admin));
    }
}
