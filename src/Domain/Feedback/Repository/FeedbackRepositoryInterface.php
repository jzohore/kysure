<?php

declare(strict_types=1);

namespace App\Domain\Feedback\Repository;

use App\Domain\Feedback\Entity\Feedback;

interface FeedbackRepositoryInterface
{
    public function save(Feedback $feedback): void;

    /**
     * @return list<Feedback>
     */
    public function findAllNewestFirst(): array;
}
