<?php

declare(strict_types=1);

namespace App\Application\Support\UseCase;

use App\Domain\Support\Entity\SupportThread;
use App\Domain\Support\Enum\SupportPriority;
use App\Domain\Support\Repository\SupportThreadRepositoryInterface;

final readonly class ChangeSupportThreadPriorityUseCase
{
    public function __construct(
        private SupportThreadRepositoryInterface $threadRepository,
    ) {
    }

    public function execute(SupportThread $thread, SupportPriority $priority): void
    {
        $thread->changePriority($priority);
        $this->threadRepository->save($thread);
    }
}
