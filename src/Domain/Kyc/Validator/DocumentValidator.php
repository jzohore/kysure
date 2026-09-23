<?php

declare(strict_types=1);

namespace App\Domain\Kyc\Validator;

use App\Domain\Compliance\Entity\ComplianceDocument;
use App\Domain\Compliance\Enum\DocumentType;
use Webmozart\Assert\Assert;

class DocumentValidator
{
    /** Version des règles de contrôle — à incrémenter à chaque évolution des règles ci-dessous. */
    public const string VERSION = '2026-09-01';

    /**
     * @return list<string> Points de vigilance détectés. Si vide, aucun signalement.
     *                      Ce n'est jamais une décision de rejet : seul le CGP tranche.
     */
    public function validate(ComplianceDocument $document): array
    {
        $errors = [];
        $data = $document->ocrData; // On accède au JSON stocké
        $stakeholder = $document->stakeholder;

        if (null === $data || [] === $data) {
            return ["Aucune donnée n'a pu être extraite du document."];
        }

        // --- RÈGLES SPÉCIFIQUES POUR LA CNI ---
        if (DocumentType::ID_CARD === $document->type && $stakeholder instanceof \App\Domain\Kyc\Entity\Stakeholder) {
            // 1. CROISEMENT D'IDENTITÉ (Stakeholder VS OCR Zone Visuelle)
            $ocrLastName = $this->normalizeString($data['last_name'] ?? '');
            $userLastName = $this->normalizeString($stakeholder->lastName); // Assure-toi d'avoir cette méthode

            $ocrFirstName = $this->normalizeString($data['first_name'] ?? '');
            $userFirstName = $this->normalizeString($stakeholder->firstName);

            // Tolérance de 2 caractères (distance de Levenshtein) pour les fautes de frappe
            if (levenshtein($ocrLastName, $userLastName) > 2) {
                $errors[] = "Identité non correspondante : Le nom sur la pièce ({$data['last_name']}) diffère du profil déclaré.";
            }

            // On vérifie si le prénom déclaré est au moins contenu dans les prénoms de la CNI
            // (ex: CNI = "JEAN PAUL", Déclaré = "JEAN")
            if (!str_contains($ocrFirstName, $userFirstName) && levenshtein($ocrFirstName, $userFirstName) > 2) {
                $errors[] = "Identité non correspondante : Le prénom sur la pièce ({$data['first_name']}) diffère du profil déclaré.";
            }

            // 2. LUTTE ANTI-FRAUDE (Zone Visuelle VS Bande MRZ)
            $mrz = $data['mrz'] ?? null;
            if ($mrz && !empty($mrz['line_1']) && !empty($mrz['line_2'])) {
                $mrzLine1 = $this->normalizeString($mrz['line_1']);
                $mrzLine2 = $this->normalizeString($mrz['line_2']);

                // Le nom de famille doit être encodé dans la ligne 1 de la MRZ
                // Ex: IDFRAALOIS<<<<< (ALOIS est bien dedans)
                if ('' !== $ocrLastName && !str_contains($mrzLine1, $ocrLastName)) {
                    $errors[] = 'Anomalie de sécurité : Le nom de famille ne correspond pas à la bande optique (MRZ). Falsification suspectée.';
                }

                // La date de naissance (format YYMMDD) doit être encodée dans la ligne 2
                // Ex: 1980-01-21 devient 800121.
                if (!empty($data['birth_date'])) {
                    $birthDate = \DateTimeImmutable::createFromFormat('Y-m-d', $data['birth_date']);
                    if ($birthDate) {
                        $mrzDate = $birthDate->format('ymd');
                        if (!str_contains($mrzLine2, $mrzDate)) {
                            $errors[] = 'Anomalie de sécurité : La date de naissance visuelle ne correspond pas à celle de la bande optique (MRZ).';
                        }
                    }
                }
            } else {
                // Si Mindee ne trouve pas de MRZ sur une CNI, c'est très louche (ou la photo est coupée)
                $errors[] = 'Bande de lecture optique (MRZ) illisible ou absente. Veuillez fournir une photo complète de la pièce.';
            }
        }

        // 3. VÉRIFICATION DE LA VALIDITÉ (Péremption)
        $expiryDateStr = $data['date_of_expiry'] ?? null;

        if (!$expiryDateStr) {
            // Si Mindee n'a pas trouvé la date, c'est souvent qu'il manque le Recto (nouveau format)
            // ou que la photo est coupée/floue.
            $errors[] = "Date d'expiration introuvable. Veuillez vous assurer de fournir une copie claire du Recto ET du Verso de la pièce d'identité.";
        } else {
            // Vérification de la péremption
            $expiryDate = \DateTimeImmutable::createFromFormat('Y-m-d', $expiryDateStr);
            $today = new \DateTimeImmutable('today'); // Heure à 00:00:00

            if ($expiryDate && $expiryDate < $today) {
                $errors[] = "Document expiré : La pièce d'identité n'est plus valide depuis le " . $expiryDate->format('d/m/Y') . '.';

                // Note de Lead Dev : En France, les anciennes cartes (bleues) majeures ont eu +5 ans de validité automatique.
                // Si ton business le permet légalement, tu pourrais rajouter +5 ans à $expiryDate ici avant de faire la comparaison.
            }
        }

        return $errors;
    }

    /**
     * Nettoie une chaîne pour la comparaison : Majuscules, sans accents, sans espaces, sans tirets.
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
