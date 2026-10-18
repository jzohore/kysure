<?php

declare(strict_types=1);

namespace App\Infrastructure\Security\Antivirus;

use App\Domain\Compliance\Exception\Document\MalwareDetectedException;
use App\Domain\Compliance\Exception\Document\VirusScannerUnavailableException;
use App\Domain\Port\VirusScannerInterface;
use Psr\Log\LoggerInterface;

/**
 * Analyse via le démon ClamAV (clamd), commande INSTREAM sur TCP : le fichier est
 * streamé au démon, qui n'a donc besoin d'aucun volume partagé avec l'application.
 *
 * @see https://docs.clamav.net/manual/Usage/Scanning.html#clamd
 */
readonly class ClamAvScanner implements VirusScannerInterface
{
    private const int CHUNK_SIZE = 8192;
    private const int CONNECT_TIMEOUT_SECONDS = 5;
    private const int IO_TIMEOUT_SECONDS = 20;

    public function __construct(
        private string $host,
        private int $port,
        private LoggerInterface $logger,
    ) {
    }

    public function scan(string $absolutePath): void
    {
        $file = @fopen($absolutePath, 'r');
        if (false === $file) {
            throw new VirusScannerUnavailableException();
        }

        $socket = @stream_socket_client(
            sprintf('tcp://%s:%d', $this->host, $this->port),
            $errorCode,
            $errorMessage,
            self::CONNECT_TIMEOUT_SECONDS,
        );
        if (false === $socket) {
            fclose($file);
            $this->logger->error('ClamAV injoignable : le dépôt est refusé (fail-closed).', [
                'host' => $this->host,
                'port' => $this->port,
                'error' => $errorMessage,
            ]);

            throw new VirusScannerUnavailableException();
        }

        stream_set_timeout($socket, self::IO_TIMEOUT_SECONDS);

        try {
            $this->write($socket, "zINSTREAM\0");

            while (!feof($file)) {
                $chunk = fread($file, self::CHUNK_SIZE);
                if (false === $chunk || '' === $chunk) {
                    break;
                }

                $this->write($socket, pack('N', strlen($chunk)) . $chunk);
            }

            // Un bloc de longueur 0 clôt le flux.
            $this->write($socket, pack('N', 0));

            $reply = stream_get_contents($socket);
        } catch (\RuntimeException $exception) {
            $this->logger->error('Échec de la communication avec ClamAV.', ['error' => $exception->getMessage()]);

            throw new VirusScannerUnavailableException($exception);
        } finally {
            fclose($socket);
            fclose($file);
        }

        try {
            self::interpret(false === $reply ? '' : $reply);
        } catch (VirusScannerUnavailableException $exception) {
            $this->logger->error('Réponse inexploitable de ClamAV.', ['reply' => false === $reply ? null : trim($reply, "\0 \n")]);

            throw $exception;
        }
    }

    /**
     * Interprète la réponse de clamd (« stream: OK », « stream: <signature> FOUND », « ... ERROR »).
     * Toute réponse inattendue est traitée comme une analyse non aboutie (fail-closed).
     *
     * @throws MalwareDetectedException
     * @throws VirusScannerUnavailableException
     */
    public static function interpret(string $reply): void
    {
        $reply = trim($reply, "\0 \n\r");

        if ('stream: OK' === $reply) {
            return;
        }

        if (1 === preg_match('/^stream: (?<signature>.+) FOUND$/', $reply, $matches)) {
            throw new MalwareDetectedException($matches['signature']);
        }

        throw new VirusScannerUnavailableException();
    }

    /**
     * @param resource $socket
     */
    private function write($socket, string $data): void
    {
        $length = strlen($data);
        $written = 0;

        while ($written < $length) {
            $bytes = @fwrite($socket, substr($data, $written));
            if (false === $bytes || 0 === $bytes) {
                throw new \RuntimeException('Écriture interrompue vers clamd.');
            }

            $written += $bytes;
        }
    }
}
