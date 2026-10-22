<?php

declare(strict_types=1);

namespace App\Infrastructure\Ocr;

use App\Domain\Compliance\Enum\DocumentType;
use App\Infrastructure\Ocr\Exception\OcrServiceUnavailableException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Client HTTP du service Python auto-hébergé `ocr-service/` (conteneur isolé, sans Internet ni secrets).
 *
 * - `extract()` : extraction locale normalisée (utilisée par {@see LocalOcrProvider}) ;
 * - `renderPdfPages()` : PDF → pages JPEG pour Textract ({@see AmazonTextractProvider}). Un PDF déposé par un
 *   client n'est ainsi jamais interprété (Ghostscript) dans le worker PHP qui détient les identifiants.
 *
 * Le service n'a aucun accès réseau : c'est ce client qui lit le fichier (chemin local ou URL temporaire du
 * stockage) et le lui envoie en flux, jamais chargé entièrement en mémoire PHP.
 *
 * Sémantique d'erreur alignée sur `OcrProviderInterface` :
 * - 413 / 415 / 422 → `\DomainException` : document illisible ou non pris en charge, définitif, pas de retry ;
 * - tout le reste (5xx, 401, 429, timeout, réponse invalide) → {@see OcrServiceUnavailableException}, réessayable.
 *
 * Aucune donnée de pièce n'est journalisée : seuls l'opération, le type, le statut HTTP et la durée le sont.
 */
readonly class OcrServiceClient
{
    /** Le service borne chaque requête à 45 s ; on lui laisse une marge réseau, pas davantage. */
    private const float IDLE_TIMEOUT_SECONDS = 30.0;
    private const float MAX_DURATION_SECONDS = 60.0;

    /** Statuts qui disent « ce document-là est inexploitable » (et non « le service a un problème »). */
    private const array UNREADABLE_STATUSES = [413, 415, 422];

    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        #[Autowire(env: 'OCR_SERVICE_URL')]
        private string $serviceUrl,
        #[Autowire(env: 'OCR_SERVICE_TOKEN')]
        private string $serviceToken,
    ) {
    }

    /**
     * @return array<string, mixed> données normalisées (clés vides omises ; `[]` = rien d'exploitable)
     */
    public function extract(DocumentType $type, string $filePath): array
    {
        $decoded = json_decode($this->post('/extract', ['type' => $type->value], $filePath, $type->value), true);

        // `{}` (rien d'exploitable) se décode en tableau vide : c'est une réponse valide.
        if (!is_array($decoded) || ([] !== $decoded && array_is_list($decoded))) {
            throw new OcrServiceUnavailableException('OCR local : réponse du service invalide.');
        }

        /* @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @return list<string> pages JPEG (binaire), au plus les 2 premières
     */
    public function renderPdfPages(string $filePath): array
    {
        $decoded = json_decode($this->post('/render', [], $filePath, 'pdf'), true);
        $pages = is_array($decoded) ? ($decoded['pages'] ?? null) : null;

        if (!is_array($pages) || [] === $pages || !array_is_list($pages)) {
            throw new OcrServiceUnavailableException('OCR local : réponse de rendu invalide.');
        }

        return array_map(static function (mixed $page): string {
            $jpeg = is_string($page) ? base64_decode($page, true) : false;
            if (false === $jpeg || !str_starts_with($jpeg, "\xFF\xD8\xFF")) {
                throw new OcrServiceUnavailableException('OCR local : page rendue invalide.');
            }

            return $jpeg;
        }, $pages);
    }

    /**
     * @param array<string, string> $fields
     *
     * @return string corps d'une réponse 200
     */
    private function post(string $endpoint, array $fields, string $filePath, string $logType): string
    {
        $stream = @fopen($filePath, 'r');
        if (false === $stream) {
            // Fichier (ou URL temporaire) inaccessible : incident technique, pas un document illisible.
            throw new OcrServiceUnavailableException('OCR local : impossible de lire le fichier à analyser.');
        }

        $startedAt = hrtime(true);

        try {
            $form = new FormDataPart([
                ...$fields,
                // Nom neutre : le chemin d'origine peut contenir un nom de client.
                'file' => new DataPart($stream, 'document', 'application/octet-stream'),
            ]);

            $response = $this->httpClient->request('POST', rtrim($this->serviceUrl, '/') . $endpoint, [
                'headers' => [...$form->getPreparedHeaders()->toArray(), 'Authorization: Bearer ' . $this->serviceToken],
                'body' => $form->bodyToIterable(),
                'timeout' => self::IDLE_TIMEOUT_SECONDS,
                'max_duration' => self::MAX_DURATION_SECONDS,
            ]);

            $status = $response->getStatusCode();
            $content = $response->getContent(false);
        } catch (ExceptionInterface $exception) {
            $this->logger->warning('OCR local : service injoignable ou trop lent.', [
                'endpoint' => $endpoint,
                'type' => $logType,
                'error' => $exception::class,
            ]);

            throw new OcrServiceUnavailableException('OCR local : service injoignable ou trop lent.', $exception->getCode(), previous: $exception);
        } finally {
            fclose($stream);
        }

        $this->logger->info('OCR local : réponse du service.', [
            'endpoint' => $endpoint,
            'type' => $logType,
            'status' => $status,
            'duration_ms' => (int) ((hrtime(true) - $startedAt) / 1_000_000),
        ]);

        if (200 === $status) {
            return $content;
        }

        if (in_array($status, self::UNREADABLE_STATUSES, true)) {
            throw new \DomainException(sprintf('Document non exploitable par le service OCR local (%s).', $this->errorCode($content)));
        }

        throw new OcrServiceUnavailableException(sprintf('OCR local : le service a répondu HTTP %d.', $status));
    }

    /** Code d'erreur stable du service (`unreadable_document`…), jamais un texte libre. */
    private function errorCode(string $content): string
    {
        $decoded = json_decode($content, true);
        $code = is_array($decoded) ? ($decoded['error'] ?? null) : null;

        return is_string($code) && 1 === preg_match('/^[a-z_]{1,40}$/', $code) ? $code : 'unknown';
    }
}
