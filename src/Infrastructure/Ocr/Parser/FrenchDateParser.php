<?php

declare(strict_types=1);

namespace App\Infrastructure\Ocr\Parser;

/**
 * Normalise une date lue par l'OCR (« 03/01/2026 », « 3 janvier 2026 », « 3 janv. 2026 »…) en `Y-m-d`.
 * Retourne null quand la date n'est pas fiable : mieux vaut « date introuvable » (le CGP vérifie)
 * qu'une date inventée.
 */
final class FrenchDateParser
{
    private const array MONTHS = [
        'janvier' => 1, 'janv' => 1, 'fevrier' => 2, 'fevr' => 2, 'fev' => 2, 'mars' => 3, 'avril' => 4, 'avr' => 4,
        'mai' => 5, 'juin' => 6, 'juillet' => 7, 'juil' => 7, 'aout' => 8, 'septembre' => 9, 'sept' => 9,
        'octobre' => 10, 'oct' => 10, 'novembre' => 11, 'nov' => 11, 'decembre' => 12, 'dec' => 12,
    ];

    public static function parse(string $raw): ?string
    {
        $text = mb_strtolower(trim($raw));
        $text = strtr($text, ['é' => 'e', 'è' => 'e', 'û' => 'u', 'ô' => 'o', 'à' => 'a', 'ç' => 'c']);

        // 03/01/2026, 3.1.2026, 03-01-2026
        if (1 === preg_match('/(?<!\d)(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{4})(?!\d)/', $text, $matches)) {
            return self::format((int) $matches[3], (int) $matches[2], (int) $matches[1]);
        }

        // 3 janvier 2026, 3 janv. 2026, 1er mars 2026
        if (1 === preg_match('/(?<!\d)(\d{1,2})(?:er)?\s+([a-z]+)\.?\s+(\d{4})(?!\d)/', $text, $matches)) {
            $month = self::MONTHS[$matches[2]] ?? null;

            return null === $month ? null : self::format((int) $matches[3], $month, (int) $matches[1]);
        }

        return null;
    }

    private static function format(int $year, int $month, int $day): ?string
    {
        // checkdate refuse le 31/02 : une lecture OCR aberrante ne devient pas une date « valide ».
        return checkdate($month, $day, $year) ? sprintf('%04d-%02d-%02d', $year, $month, $day) : null;
    }
}
