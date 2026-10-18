<?php

declare(strict_types=1);

namespace App\Domain\Kyc\Validator;

use App\Domain\Compliance\Entity\ComplianceDocument;
use App\Domain\Compliance\Enum\DocumentType;

use function Symfony\Component\Clock\now;

use Webmozart\Assert\Assert;

/**
 * Contrôles métier appliqués aux données lues par l'OCR, **par type de pièce**.
 *
 * Ne produit que des points de vigilance : jamais une décision. Seul le CGP accepte ou rejette.
 * Un type sans règle (pas d'OCR au MVP) ne produit aucun signalement — ce n'est pas une erreur,
 * la pièce relève d'une vérification manuelle.
 */
class DocumentValidator
{
    /** Version des règles de contrôle — à incrémenter à chaque évolution des règles ci-dessous. */
    public const string VERSION = '2026-10-19';

    /** Ancienneté maximale d'un justificatif de domicile / d'un extrait K-bis (libellés de {@see DocumentType}). */
    private const int MAX_DOCUMENT_AGE_MONTHS = 3;

    /** Tolérance (distance de Levenshtein) pour absorber les fautes de lecture OCR. */
    private const int NAME_TOLERANCE = 2;

    /**
     * @return list<string> Points de vigilance détectés. Si vide, aucun signalement.
     *                      Ce n'est jamais une décision de rejet : seul le CGP tranche.
     */
    public function validate(ComplianceDocument $document): array
    {
        $data = $document->ocrData;

        if (null === $data || [] === $data) {
            return ["Aucune donnée n'a pu être extraite du document."];
        }

        $expected = ExpectedIdentity::forDocument($document);

        return match ($document->type) {
            DocumentType::ID_CARD, DocumentType::PASSPORT, DocumentType::RESIDENCE_PERMIT => $this->validateIdentityDocument($document->type, $data, $expected),
            DocumentType::PROOF_OF_ADDRESS => $this->validateProofOfAddress($data, $expected),
            DocumentType::KBIS => $this->validateKbis($data, $expected),
            default => [],
        };
    }

    // =========================================================================
    // PIÈCES D'IDENTITÉ
    // =========================================================================

    /**
     * @param array<string, mixed> $data
     *
     * @return list<string>
     */
    private function validateIdentityDocument(DocumentType $type, array $data, ExpectedIdentity $expected): array
    {
        $errors = [];

        $ocrLastName = $this->normalizeString($this->text($data, 'last_name'));
        $ocrFirstName = $this->normalizeString($this->text($data, 'first_name'));

        // 1. CROISEMENT D'IDENTITÉ (identité du dossier VS zone visuelle)
        if (null !== $expected->lastName) {
            $userLastName = $this->normalizeString($expected->lastName);

            if ('' === $ocrLastName) {
                $errors[] = 'Nom illisible sur la pièce : recoupement avec le dossier impossible, vérification manuelle requise.';
            } elseif (levenshtein($ocrLastName, $userLastName) > self::NAME_TOLERANCE) {
                $errors[] = sprintf('Identité non correspondante : le nom sur la pièce (%s) diffère de celui du dossier.', $this->text($data, 'last_name'));
            }
        }

        if (null !== $expected->firstName) {
            $userFirstName = $this->normalizeString($expected->firstName);

            if ('' === $ocrFirstName) {
                $errors[] = 'Prénom illisible sur la pièce : recoupement avec le dossier impossible, vérification manuelle requise.';
            } elseif (!str_contains($ocrFirstName, $userFirstName) && levenshtein($ocrFirstName, $userFirstName) > self::NAME_TOLERANCE) {
                // Prénom déclaré au moins contenu dans les prénoms de la pièce (ex: pièce « JEAN PAUL », dossier « JEAN »).
                $errors[] = sprintf('Identité non correspondante : le prénom sur la pièce (%s) diffère de celui du dossier.', $this->text($data, 'first_name'));
            }
        }

        // 2. COHÉRENCE ZONE VISUELLE VS BANDE MRZ (hors titre de séjour : pas de MRZ exigée)
        $mrz = $data['mrz'] ?? null;
        if (is_array($mrz) && !empty($mrz['line_1']) && !empty($mrz['line_2'])) {
            $errors = [...$errors, ...$this->checkMrzCoherence($mrz, $ocrLastName, $data)];
        } elseif (in_array($type, [DocumentType::ID_CARD, DocumentType::PASSPORT], true)) {
            // Une photo du seul recto (CNI) ou coupée n'a pas la bande : on demande une pièce complète.
            $errors[] = 'Bande de lecture optique (MRZ) illisible ou absente. Veuillez fournir une photo complète de la pièce.';
        }

        // 3. VALIDITÉ (péremption)
        $expiryDate = $this->date($data, 'date_of_expiry');
        if (!$expiryDate instanceof \DateTimeImmutable) {
            $errors[] = "Date d'expiration introuvable. Veuillez vous assurer de fournir une copie claire du recto ET du verso de la pièce d'identité.";
        } elseif ($expiryDate < now()->setTime(0, 0)) {
            $errors[] = sprintf("Document expiré : la pièce d'identité n'est plus valide depuis le %s.", $expiryDate->format('d/m/Y'));
        }

        return $errors;
    }

    /**
     * @param array<array-key, mixed> $mrz
     * @param array<string, mixed>    $data
     *
     * @return list<string>
     */
    private function checkMrzCoherence(array $mrz, string $ocrLastName, array $data): array
    {
        $errors = [];

        // Le nom est encodé en ligne 1 (ancien format 2 lignes / passeport) ou en ligne 3 (CNI 3 lignes).
        $mrzNames = $this->normalizeString(sprintf('%s %s', $this->text($mrz, 'line_1'), $this->text($mrz, 'line_3')));
        $mrzLine2 = $this->normalizeString($this->text($mrz, 'line_2'));

        if ('' !== $ocrLastName && !str_contains($mrzNames, $ocrLastName)) {
            $errors[] = 'Incohérence : le nom de famille lu sur la pièce ne correspond pas à la bande optique (MRZ). À vérifier.';
        }

        // La date de naissance (YYMMDD) est encodée en ligne 2. Ex : 1980-01-21 → 800121.
        $birthDate = $this->date($data, 'birth_date');
        if ($birthDate instanceof \DateTimeImmutable && !str_contains($mrzLine2, $birthDate->format('ymd'))) {
            $errors[] = 'Incohérence : la date de naissance lue sur la pièce ne correspond pas à la bande optique (MRZ). À vérifier.';
        }

        return $errors;
    }

    // =========================================================================
    // JUSTIFICATIF DE DOMICILE
    // =========================================================================

    /**
     * @param array<string, mixed> $data
     *
     * @return list<string>
     */
    private function validateProofOfAddress(array $data, ExpectedIdentity $expected): array
    {
        $errors = [];

        $holder = $this->text($data, 'holder_name');
        if (null !== $expected->lastName) {
            if ('' === $holder) {
                $errors[] = 'Titulaire du justificatif illisible : recoupement avec le dossier impossible, vérification manuelle requise.';
            } elseif (!$this->holderMatches($holder, $expected->lastName)) {
                $errors[] = sprintf('Le titulaire du justificatif (%s) ne correspond pas au client du dossier.', $holder);
            }
        }

        if ('' === $this->text($data, 'address')) {
            $errors[] = 'Adresse illisible sur le justificatif : vérification manuelle requise.';
        }

        return [...$errors, ...$this->checkFreshness($data, 'justificatif', 'du justificatif')];
    }

    // =========================================================================
    // EXTRAIT K-BIS
    // =========================================================================

    /**
     * @param array<string, mixed> $data
     *
     * @return list<string>
     */
    private function validateKbis(array $data, ExpectedIdentity $expected): array
    {
        $errors = [];

        $companyName = $this->text($data, 'company_name');
        if (null !== $expected->companyName && '' !== $companyName) {
            $found = $this->normalizeString($companyName);
            $declared = $this->normalizeString($expected->companyName);

            if (!str_contains($found, $declared) && !str_contains($declared, $found) && levenshtein($found, $declared) > self::NAME_TOLERANCE) {
                $errors[] = sprintf('La dénomination de l\'extrait K-bis (%s) diffère de celle du dossier.', $companyName);
            }
        }

        $registration = preg_replace('/\D/', '', $this->text($data, 'registration_number'));
        if (null !== $expected->siren && '' !== (string) $registration && !str_contains((string) $registration, $expected->siren)) {
            $errors[] = 'Le SIREN de l\'extrait K-bis diffère de celui du dossier.';
        }

        return [...$errors, ...$this->checkFreshness($data, 'extrait', 'de l\'extrait')];
    }

    // =========================================================================
    // OUTILS
    // =========================================================================

    /**
     * Fraîcheur (« moins de 3 mois ») d'un justificatif ou d'un extrait daté.
     *
     * @param array<string, mixed> $data
     *
     * @return list<string>
     */
    private function checkFreshness(array $data, string $noun, string $ofTheNoun): array
    {
        $documentDate = $this->date($data, 'document_date');

        if (!$documentDate instanceof \DateTimeImmutable) {
            return [sprintf('Date %s introuvable : fraîcheur (moins de %d mois) à vérifier.', $ofTheNoun, self::MAX_DOCUMENT_AGE_MONTHS)];
        }

        if ($documentDate < now()->setTime(0, 0)->modify(sprintf('-%d months', self::MAX_DOCUMENT_AGE_MONTHS))) {
            return [sprintf('Le %s date du %s : il a plus de %d mois.', $noun, $documentDate->format('d/m/Y'), self::MAX_DOCUMENT_AGE_MONTHS)];
        }

        return [];
    }

    /**
     * Le titulaire d'un justificatif peut être « M. et Mme DUPONT » ou « DUPONT Jean » : on cherche le nom
     * attendu dans l'ensemble du libellé, puis mot à mot avec la tolérance de lecture.
     */
    private function holderMatches(string $holder, string $expectedLastName): bool
    {
        $expected = $this->normalizeString($expectedLastName);
        if ('' === $expected || str_contains($this->normalizeString($holder), $expected)) {
            return true;
        }

        $tokens = preg_split('/[^\p{L}\p{N}]+/u', $holder, -1, \PREG_SPLIT_NO_EMPTY);
        Assert::isArray($tokens);

        return array_any($tokens, fn (string $token): bool => levenshtein($this->normalizeString($token), $expected) <= self::NAME_TOLERANCE);
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function text(array $data, string $key): string
    {
        $value = $data[$key] ?? '';

        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function date(array $data, string $key): ?\DateTimeImmutable
    {
        $value = $this->text($data, $key);
        if ('' === $value) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return false === $date ? null : $date;
    }

    /**
     * Nettoie une chaîne pour la comparaison : majuscules, sans accents, sans espaces, sans tirets.
     * Ex : "Jean-François" → "JEANFRANCOIS".
     */
    private function normalizeString(string $string): string
    {
        if ('' === $string || '0' === $string) {
            return '';
        }

        // Remplacement des accents basique
        $unwanted = [
            'Š' => 'S', 'š' => 's', 'Ž' => 'Z', 'ž' => 'z', 'À' => 'A', 'Á' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'A', 'Å' => 'A', 'Æ' => 'A', 'Ç' => 'C', 'È' => 'E', 'É' => 'E',
            'Ê' => 'E', 'Ë' => 'E', 'Ì' => 'I', 'Í' => 'I', 'Î' => 'I', 'Ï' => 'I', 'Ñ' => 'N', 'Ò' => 'O', 'Ó' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ö' => 'O', 'Ø' => 'O', 'Ù' => 'U',
            'Ú' => 'U', 'Û' => 'U', 'Ü' => 'U', 'Ý' => 'Y', 'Þ' => 'B', 'ß' => 'Ss', 'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'æ' => 'a', 'ç' => 'c',
            'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ð' => 'o', 'ñ' => 'n', 'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o',
            'ö' => 'o', 'ø' => 'o', 'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ý' => 'y', 'þ' => 'b', 'ÿ' => 'y',
        ];
        $str = strtr($string, $unwanted);

        // Ne garde que les lettres et chiffres
        $str = preg_replace('/[^a-zA-Z0-9]/', '', $str);
        Assert::notNull($str);

        return strtoupper($str);
    }
}
