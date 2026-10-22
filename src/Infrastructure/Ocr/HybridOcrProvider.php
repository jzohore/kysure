<?php

declare(strict_types=1);

namespace App\Infrastructure\Ocr;

use App\Domain\Compliance\Enum\DocumentType;
use App\Domain\Port\OcrProviderInterface;
use Psr\Log\LoggerInterface;

/**
 * Mode `hybrid` : chaque type de pièce va au moteur le plus adapté (docs/specs/ocr-des-pieces-deposees.md § 2 bis).
 *
 * | Type                                   | Moteur                                                        |
 * |----------------------------------------|---------------------------------------------------------------|
 * | PROOF_OF_ADDRESS, RIB                  | local (PDF natifs : zéro facturation, données chez nous)      |
 * | KBIS                                   | local, puis Textract si le local n'a rien reconnu             |
 * | ID_CARD, PASSPORT, RESIDENCE_PERMIT    | Textract ; MRZ locale validée en second avis (sans coût)      |
 * | autres                                 | aucun OCR (vérification manuelle)                             |
 *
 * Une panne TECHNIQUE du service local n'est jamais « rattrapée » en payant Textract : elle remonte
 * (retries Messenger, puis signal sur la pièce). Seul un document que le local ne sait pas lire
 * (`\DomainException` ou aucune donnée) bascule sur Textract, pour le K-bis.
 */
readonly class HybridOcrProvider implements OcrProviderInterface
{
    /** Champs d'identité qu'une MRZ validée peut combler quand Textract ne les a pas lus. */
    private const array MRZ_FILLABLE_FIELDS = ['last_name', 'first_name', 'birth_date', 'date_of_expiry', 'id_number'];

    public function __construct(
        private OcrProviderInterface $local,
        private OcrProviderInterface $textract,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function extractData(DocumentType $type, string $filePath): array
    {
        return match ($type) {
            DocumentType::PROOF_OF_ADDRESS, DocumentType::RIB => $this->local->extractData($type, $filePath),
            DocumentType::KBIS => $this->localThenTextract($type, $filePath),
            DocumentType::ID_CARD, DocumentType::PASSPORT, DocumentType::RESIDENCE_PERMIT => $this->textractWithMrzSecondOpinion($type, $filePath),
            default => throw new \DomainException(sprintf('Aucun moteur OCR configuré pour le type « %s » : vérification manuelle.', $type->value)),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function localThenTextract(DocumentType $type, string $filePath): array
    {
        try {
            $data = $this->local->extractData($type, $filePath);
        } catch (\DomainException) {
            return $this->textract->extractData($type, $filePath);
        }

        // Un K-bis reconnu porte au moins une dénomination ou un SIREN valide (clé de Luhn vérifiée par le service).
        if (empty($data['company_name']) && empty($data['registration_number'])) {
            return $this->textract->extractData($type, $filePath);
        }

        return $data;
    }

    /**
     * Textract reste la référence pour les photos de pièces d'identité. Le service local relit la bande MRZ :
     * si toutes ses clés de contrôle sont valides, c'est un second avis fiable et gratuit — il remplace la MRZ
     * non vérifiée de Textract et comble ses champs manquants, sans jamais écraser ce que Textract a lu
     * (le validateur détecte ainsi un écart entre zone visuelle et MRZ).
     *
     * @return array<string, mixed>
     */
    private function textractWithMrzSecondOpinion(DocumentType $type, string $filePath): array
    {
        $data = $this->textract->extractData($type, $filePath);

        try {
            $local = $this->local->extractData($type, $filePath);
        } catch (\Throwable $exception) {
            // Le second avis est optionnel : sa panne ne doit pas faire échouer une analyse Textract réussie.
            $this->logger->warning('OCR hybride : second avis MRZ indisponible.', [
                'type' => $type->value,
                'error' => $exception::class,
            ]);

            return $data;
        }

        $mrz = $local['mrz'] ?? null;
        if (!is_array($mrz) || empty($mrz['line_1']) || empty($mrz['line_2'])) {
            return $data; // Pas de MRZ à clés valides côté local : rien à ajouter.
        }

        $data['mrz'] = $mrz;
        foreach (self::MRZ_FILLABLE_FIELDS as $field) {
            if (empty($data[$field]) && !empty($local[$field])) {
                $data[$field] = $local[$field];
            }
        }

        return $data;
    }
}
