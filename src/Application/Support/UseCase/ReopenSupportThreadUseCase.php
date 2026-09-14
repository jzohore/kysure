<?php

declare(strict_types=1);

namespace App\Application\Support\UseCase;

use App\Domain\Support\Entity\SupportThread;
use App\Domain\Support\Repository\SupportThreadRepositoryInterface;

final readonly class ReopenSupportThreadUseCase
{
    public function __construct(
        private SupportThreadRepositoryInterface $threadRepository,
    ) {
    }

    public function execute(SupportThread $thread): void
    {
        $thread->reopen();
        $this->threadRepository->save($thread);
    }
}
