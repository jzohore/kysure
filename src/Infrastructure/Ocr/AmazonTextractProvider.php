<?php

declare(strict_types=1);

namespace App\Infrastructure\Ocr;

use App\Domain\Compliance\Enum\DocumentType;
use App\Domain\Port\OcrProviderInterface;
use App\Infrastructure\Ocr\Parser\FrenchDateParser;
use Aws\Textract\TextractClient;
use Psr\Log\LoggerInterface;

readonly class AmazonTextractProvider implements OcrProviderInterface
{
    public function __construct(
        private TextractClient $textractClient,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function extractData(DocumentType $type, string $filePath): array
    {
        $pagesBytes = $this->extractPagesAsJpeg($filePath);

        try {
            // ---------------------------------------------------------
            // 🪪 1. PIÈCES D'IDENTITÉ (AnalyzeID + Fallback)
            // ---------------------------------------------------------
            if (in_array($type, [DocumentType::ID_CARD, DocumentType::PASSPORT, DocumentType::RESIDENCE_PERMIT], true)) {
                $documentPages = array_map(static fn (string $bytes): array => ['Bytes' => $bytes], $pagesBytes);
                $result = $this->textractClient->analyzeID(['DocumentPages' => $documentPages]);

                $data = $this->formatIdResponse($result->toArray());

                // Fallback pour les anciennes CNI Françaises
                if (empty($data['birth_date']) || empty($data['mrz'])) {
                    $this->logger->info('AnalyzeID incomplet. Lancement du Fallback OCR...');
                    $data = $this->fallbackForFrenchId($pagesBytes, $data);
                }

                return $this->autocorrectWithMrz($data);
            }

            // ---------------------------------------------------------
            // 📄 2. DOCUMENTS GÉNÉRIQUES (AnalyzeDocument + Queries)
            // ---------------------------------------------------------
            $queries = $this->buildQueriesForDocument($type);

            if ([] === $queries) {
                $this->logger->info('Aucune requête OCR configurée pour le type : ' . $type->value);

                return [];
            }

            $result = $this->textractClient->analyzeDocument([
                'Document' => ['Bytes' => $pagesBytes[0]],
                'FeatureTypes' => ['QUERIES'],
                'QueriesConfig' => ['Queries' => $queries],
            ]);

            return $this->normalizeDocumentDate($this->formatQueryResponse($result->toArray()));
        } catch (\Exception $e) {
            $this->logger->error('Erreur AWS Textract : ' . $e->getMessage());
            throw $e;
        }
    }

    // =========================================================================
    // SECTION : FORMATAGE ET MAPPING PRINCIPAL
    // =========================================================================

    /**
     * @param array<string, mixed> $awsResponse
     *
     * @return array<string, mixed>
     */
    private function formatIdResponse(array $awsResponse): array
    {
        $fields = $awsResponse['IdentityDocuments'][0]['IdentityDocumentFields'] ?? [];

        $rawAws = [];
        foreach ($fields as $field) {
            $type = strtoupper($field['Type']['Text'] ?? '');
            $rawAws[$type] = $field['ValueDetection']['Text'] ?? '';
        }

        $data = [
            'last_name' => $rawAws['LAST_NAME'] ?? '',
            'id_number' => $rawAws['DOCUMENT_NUMBER'] ?? '',
            'birth_place' => $rawAws['PLACE_OF_BIRTH'] ?? '',
            'gender' => $rawAws['SEX'] ?? '',
            'nationality' => $rawAws['NATIONALITY'] ?? '',
            'first_name' => $rawAws['FIRST_NAME'] ?? $rawAws['MIDDLE_NAME'] ?? $rawAws['SUFFIX'] ?? '',
            'birth_date' => $this->formatAwsDate($rawAws['DATE_OF_BIRTH'] ?? ''),
            'date_of_expiry' => $this->formatAwsDate($rawAws['EXPIRATION_DATE'] ?? ''),
        ];

        // 🪄 ANTI-HALLUCINATION : Une vraie MRZ DOIT contenir des chevrons !
        $mrzCode = $rawAws['MRZ_CODE'] ?? '';
        if (!empty($mrzCode) && str_contains((string) $mrzCode, '<')) {
            $lines = explode("\n", str_replace("\r", '', $mrzCode));
            $data['mrz'] = [
                'line_1' => $lines[0],
                'line_2' => $lines[1] ?? '',
                'line_3' => $lines[2] ?? '', // 💡 Ajout de la ligne 3 pour les nouvelles CNI
            ];
        }

        return array_filter($data);
    }

    // =========================================================================
    // SECTION : LE FALLBACK (Couteau Suisse pour la CNI Française)
    // =========================================================================

    /**
     * @param list<string>         $pagesBytes
     * @param array<string, mixed> $existingData
     *
     * @return array<string, mixed>
     */
    private function fallbackForFrenchId(array $pagesBytes, array $existingData): array
    {
        $queries = [
            ['Text' => 'What is the date of birth?', 'Alias' => 'birth_date'],
            ['Text' => 'What is the expiration date?', 'Alias' => 'date_of_expiry'],
            ['Text' => 'What is the first name?', 'Alias' => 'first_name'],
        ];

        // On itère sur chaque page (Index 0 = Recto, Index 1 = Verso)
        foreach ($pagesBytes as $index => $pageBytes) {
            $result = $this->textractClient->analyzeDocument([
                'Document' => ['Bytes' => $pageBytes],
                'FeatureTypes' => ['QUERIES'],
                'QueriesConfig' => ['Queries' => $queries],
            ])->toArray();

            $blocks = $result['Blocks'] ?? [];
            $queryData = $this->formatQueryResponse($result);

            // Création du bloc de texte brut de la page courante
            $fullText = implode("\n", array_map(
                static fn (array $b): string => $b['Text'] ?? '',
                array_filter($blocks, static fn (array $b): bool => ($b['BlockType'] ?? '') === 'LINE')
            ));

            // 1. Extraction du Prénom (Uniquement sur la page 0 - Recto)
            if (0 === $index && empty($existingData['first_name'])) {
                // 🪄 On passe les $blocks au lieu du $fullText
                $existingData['first_name'] = $this->extractFirstName($blocks, $queryData['first_name'] ?? '');
            }
            // 2. Extraction des Dates (Recto ou Verso)
            $existingData = $this->extractDates($fullText, $queryData, $existingData);

            // 3. Extraction de la MRZ (Généralement sur le Recto)
            if (empty($existingData['mrz'])) {
                $mrz = $this->extractMrz($blocks);
                if ([] !== $mrz) {
                    $existingData['mrz'] = $mrz;
                }
            }

            // Optimisation : On arrête de payer l'API si on a trouvé les éléments vitaux
            if (!empty($existingData['mrz']) && !empty($existingData['date_of_expiry'])) {
                break;
            }
        }

        return array_filter($existingData);
    }

    /**
     * @param list<array<string, mixed>> $blocks
     */
    private function extractFirstName(array $blocks, string $fallbackQueryFirstName): string
    {
        $forbiddenWords = ['RUE', 'AVENUE', 'BOULEVARD', 'ALLEE', 'PLACE', 'CODE', 'DATE', 'DELIVREE', 'VALABLE', 'PREFECTURE', 'SIGNATURE', 'NOM', 'SEXE', 'NE(E)', 'NÉ(E)'];

        // 1. On récupère toutes les lignes proprement
        $lines = array_values(array_filter(array_map(
            static fn (array $b): string => strtoupper(trim($b['Text'] ?? '')),
            array_filter($blocks, static fn (array $b): bool => ($b['BlockType'] ?? '') === 'LINE')
        )));

        $potentialFirstName = '';

        // 2. On lit le document ligne par ligne
        foreach ($lines as $i => $line) {
            if (str_contains($line, 'PRENOM') || str_contains($line, 'PRÉNOM')) {
                // Cas A : Tout est sur la même ligne (ex: "PRÉNOM(S) : JUNIOR")
                $parts = explode(':', $line);
                if (count($parts) > 1 && !in_array(trim($parts[1]), ['', '0'], true)) {
                    $potentialFirstName = trim($parts[1]);
                }
                // Cas B : Le prénom est sur la ligne d'en dessous (La cause de notre bug !)
                elseif (isset($lines[$i + 1])) {
                    $potentialFirstName = trim($lines[$i + 1]);
                }
                break;
            }
        }

        // 3. Validation finale avec la liste blanche/noire
        if ('' !== $potentialFirstName && '0' !== $potentialFirstName) {
            foreach (explode(' ', $potentialFirstName) as $word) {
                if (in_array($word, $forbiddenWords, true)) {
                    return $fallbackQueryFirstName; // On rejette
                }
            }

            return $potentialFirstName;
        }

        return $fallbackQueryFirstName;
    }

    /**
     * @param array<string, mixed> $queryData
     * @param array<string, mixed> $existingData
     *
     * @return array<string, mixed>
     */
    private function extractDates(string $fullText, array $queryData, array $existingData): array
    {
        // Expiration
        if (empty($existingData['date_of_expiry'])) {
            if (preg_match('/jusqu\'au[\s:]*([0-9]{2}[\.\/][0-9]{2}[\.\/][0-9]{4})/i', $fullText, $matches)) {
                $existingData['date_of_expiry'] = $this->formatAwsDate($matches[1]);
            } elseif (!empty($queryData['date_of_expiry'])) {
                $existingData['date_of_expiry'] = $this->formatAwsDate((string) $queryData['date_of_expiry']);
            }
        }

        // Naissance
        if (empty($existingData['birth_date'])) {
            if (preg_match('/N[eé]\(e\) le[\s:]*([0-9]{2}[\.\/][0-9]{2}[\.\/][0-9]{4})/i', $fullText, $matches)) {
                $existingData['birth_date'] = $this->formatAwsDate($matches[1]);
            } elseif (!empty($queryData['birth_date'])) {
                $existingData['birth_date'] = $this->formatAwsDate((string) $queryData['birth_date']);
            }
        }

        return $existingData;
    }

    /**
     * @param list<array<string, mixed>> $blocks
     *
     * @return array{line_1: string, line_2: string, line_3: string}|array{}
     */
    private function extractMrz(array $blocks): array
    {
        $mrzLines = [];
        foreach ($blocks as $block) {
            if (($block['BlockType'] ?? '') === 'LINE') {
                $text = strtoupper(str_replace([' ', '«', '»'], ['', '<', '<'], $block['Text'] ?? ''));

                // Règle souple : 28 caractères min et des chevrons
                if (strlen($text) >= 28 && substr_count($text, '<') >= 4) {
                    $mrzLines[] = $text;
                }
            }
        }

        // Si on a capturé 2 lignes (Ancienne CNI) ou 3 lignes (Nouvelle CNI)
        if (count($mrzLines) >= 2) {
            // On trie pour mettre la ligne qui contient IDFRA en premier
            usort($mrzLines, static fn (string $a, string $b): int => str_contains($b, 'IDFRA') <=> str_contains($a, 'IDFRA'));

            return [
                'line_1' => $mrzLines[0],
                'line_2' => $mrzLines[1],
                'line_3' => $mrzLines[2] ?? '',
            ];
        }

        return [];
    }

    // =========================================================================
    // SECTION : OUTILS & UTILITAIRES (Helpers)
    // =========================================================================

    private function formatAwsDate(string $awsDate): string
    {
        if ('' === $awsDate || '0' === $awsDate) {
            return '';
        }

        $cleanedDate = preg_replace('/[^0-9]/', '.', $awsDate);
        if (null === $cleanedDate) {
            return $awsDate;
        }

        $date = \DateTimeImmutable::createFromFormat('d.m.Y', $cleanedDate);

        return $date ? $date->format('Y-m-d') : $awsDate;
    }

    /**
     * @return list<array{Text: string, Alias: string}>
     */
    private function buildQueriesForDocument(DocumentType $documentType): array
    {
        return match ($documentType) {
            DocumentType::KBIS => [
                ['Text' => 'What is the company name?', 'Alias' => 'company_name'],
                ['Text' => 'What is the SIREN or registration number?', 'Alias' => 'registration_number'],
                ['Text' => 'What is the date of the extract?', 'Alias' => 'document_date'],
            ],
            // Facture, quittance ou avis : titulaire, adresse et date du document (fraîcheur « moins de 3 mois »).
            DocumentType::PROOF_OF_ADDRESS => [
                ['Text' => 'What is the name of the customer or account holder?', 'Alias' => 'holder_name'],
                ['Text' => 'What is the customer address?', 'Alias' => 'address'],
                ['Text' => 'What is the date of the document or invoice?', 'Alias' => 'document_date'],
            ],
            DocumentType::SOURCE_OF_FUNDS => [
                ['Text' => 'What is the total amount?', 'Alias' => 'amount'],
                ['Text' => 'What is the bank name?', 'Alias' => 'bank_name'],
            ],
            DocumentType::FINANCIAL_STATEMENTS => [
                ['Text' => 'What is the revenue?', 'Alias' => 'revenue'],
                ['Text' => 'What is the net income?', 'Alias' => 'net_income'],
            ],
            default => [],
        };
    }

    /**
     * `document_date` revient en texte libre (« 3 janvier 2026 ») : on le ramène en `Y-m-d`, ou on
     * l'écarte s'il n'est pas fiable (le validateur signale alors « date introuvable »).
     *
     * @param array<string, string> $data
     *
     * @return array<string, string>
     */
    private function normalizeDocumentDate(array $data): array
    {
        if (isset($data['document_date'])) {
            $date = FrenchDateParser::parse($data['document_date']);
            if (null === $date) {
                unset($data['document_date']);
            } else {
                $data['document_date'] = $date;
            }
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $awsResponse
     *
     * @return array<string, string>
     */
    private function formatQueryResponse(array $awsResponse): array
    {
        $data = [];
        $blocks = $awsResponse['Blocks'] ?? [];
        $resultsById = [];

        foreach ($blocks as $block) {
            if (($block['BlockType'] ?? '') === 'QUERY_RESULT') {
                $resultsById[$block['Id']] = $block['Text'] ?? '';
            }
        }

        foreach ($blocks as $block) {
            if (($block['BlockType'] ?? '') === 'QUERY' && isset($block['Query']['Alias'], $block['Relationships'])) {
                foreach ($block['Relationships'] as $rel) {
                    if ('ANSWER' === $rel['Type']) {
                        $answerId = $rel['Ids'][0] ?? null;
                        if (null !== $answerId && isset($resultsById[$answerId])) {
                            $data[$block['Query']['Alias']] = $resultsById[$answerId];
                        }
                    }
                }
            }
        }

        return array_filter($data);
    }

    /**
     * @return list<string>
     */
    private function extractPagesAsJpeg(string $filePathOrUrl): array
    {
        if (filter_var($filePathOrUrl, \FILTER_VALIDATE_URL)) {
            $localTmpFile = sys_get_temp_dir() . '/ocr_tmp_' . uniqid();
            // copy() streame par blocs : le fichier n'est jamais entièrement en RAM PHP.
            if (!copy($filePathOrUrl, $localTmpFile)) {
                throw new \RuntimeException("Impossible de télécharger le fichier depuis l'URL.");
            }
        } else {
            $localTmpFile = $filePathOrUrl;
        }

        try {
            if ('application/pdf' === mime_content_type($localTmpFile)) {
                // 🛡️ ImageMagick alloue HORS memory_limit PHP : on le borne explicitement (process-wide),
                // pour qu'un gros PDF ne fasse pas tuer le worker par le noyau en plein message.
                \Imagick::setResourceLimit(\Imagick::RESOURCETYPE_MEMORY, 128 * 1024 * 1024);
                \Imagick::setResourceLimit(\Imagick::RESOURCETYPE_MAP, 256 * 1024 * 1024);

                $imagick = new \Imagick();
                $imagick->setResolution(250, 250);
                // Seules les 2 premières pages partent à Textract : on ne rastérise QUE celles-là
                // (un relevé de 30 pages coûtait ~48 Mo par page rastérisée pour rien).
                $imagick->readImage($localTmpFile . '[0-1]');

                $pages = [];
                $numPages = min($imagick->getNumberImages(), 2);

                for ($i = 0; $i < $numPages; ++$i) {
                    $imagick->setIteratorIndex($i);
                    $imagick->setImageFormat('jpeg');
                    $pages[] = $imagick->getImageBlob();
                }

                $imagick->clear();
                $imagick->destroy();

                return $pages;
            }

            $content = file_get_contents($localTmpFile);
            if (false === $content) {
                throw new \RuntimeException('Impossible de lire le fichier OCR.');
            }

            return [$content];
        } finally {
            if (filter_var($filePathOrUrl, \FILTER_VALIDATE_URL) && file_exists($localTmpFile)) {
                unlink($localTmpFile);
            }
        }
    }

    /**
     * L'Autocorrecteur KYC : Utilise la MRZ (Source de vérité) pour corriger les fautes d'OCR de la zone visuelle.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function autocorrectWithMrz(array $data): array
    {
        if (empty($data['mrz']['line_1']) || empty($data['mrz']['line_2'])) {
            return $data; // Pas de MRZ, on ne peut rien corriger
        }

        $mrz = $data['mrz'];
        $mrzFirstName = '';

        // 🪄 CAS 1 : Nouvelle CNI (3 lignes de 30 caractères)
        if (isset($mrz['line_3']) && strlen($mrz['line_1']) >= 30) {
            // La ligne 3 contient : NOM<<PRENOM1<PRENOM2<<<<
            $parts = explode('<<', rtrim($mrz['line_3'], '<'));
            if (count($parts) >= 2) {
                // On récupère le premier prénom
                $mrzFirstName = explode('<', $parts[1])[0];
            }
        }
        // 🪄 CAS 2 : Ancienne CNI Bleue (2 lignes de 36 caractères)
        elseif (!isset($mrz['line_3']) && strlen($mrz['line_1']) >= 36) {
            // La ligne 2 contient : Numéro(12) + Clé(1) + Prénom(14) + ...
            // Ex: 210175M504915JUNIOR<<<<<<<<
            $mrzFirstNameRaw = substr($mrz['line_2'], 13, 14);
            $mrzFirstName = rtrim(explode('<', $mrzFirstNameRaw)[0], '<');
        }

        // 🪄 CORRECTION AUTOMATIQUE
        if ('' !== $mrzFirstName && '0' !== $mrzFirstName) {
            $visualFirstName = strtoupper($data['first_name'] ?? '');

            // Si la zone visuelle est vide, OU si elle a une faute de frappe mineure (ex: DAUHNA vs DAIHNA)
            // On utilise la distance de Levenshtein (tolérance de 2 caractères d'erreur)
            if ('' === $visualFirstName || '0' === $visualFirstName || levenshtein($visualFirstName, $mrzFirstName) <= 2) {
                $data['first_name'] = $mrzFirstName; // On écrase l'erreur de l'IA par la vérité de la MRZ !
            }
        }

        return $data;
    }
}
