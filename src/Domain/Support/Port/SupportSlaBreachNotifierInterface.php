<?php

declare(strict_types=1);

namespace App\Domain\Support\Port;

use App\Domain\Support\Entity\SupportThread;

/**
 * Alerte un canal externe (Slack en pratique) qu'un ticket support a dépassé son échéance de
 * première réponse (SLA) sans avoir été traité.
 */
interface SupportSlaBreachNotifierInterface
{
    public function alert(SupportThread $thread): void;
}
