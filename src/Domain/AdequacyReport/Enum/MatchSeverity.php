<?php

declare(strict_types=1);

namespace App\Domain\AdequacyReport\Enum;

/**
 * Gravité d'un constat du moteur d'adéquation. Un constat BLOQUANT n'interdit jamais la
 * poursuite : il impose au CGP une justification écrite, tracée en audit (le conseil reste
 * sa responsabilité, KYSURE matérialise la justification).
 */
enum MatchSeverity: string
{
    case CONFORME = 'conforme';
    case ATTENTION = 'attention';
    case BLOQUANT = 'bloquant';

    public function getLabel(): string
    {
        return match ($this) {
            self::CONFORME => 'Conforme',
            self::ATTENTION => 'Point d\'attention',
            self::BLOQUANT => 'Bloquant',
        };
    }

    /** Ordre de gravité, pour retenir le pire constat d'une vérification. */
    public function weight(): int
    {
        return match ($this) {
            self::CONFORME => 0,
            self::ATTENTION => 1,
            self::BLOQUANT => 2,
        };
    }
}
