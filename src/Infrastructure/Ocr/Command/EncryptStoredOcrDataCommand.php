<?php

declare(strict_types=1);

namespace App\Infrastructure\Ocr\Command;

use App\Infrastructure\EventSubscriber\EncryptionListener;
use App\Infrastructure\Security\MessageCipher;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Chiffre les analyses OCR déjà stockées en clair (lot 4) : les nouvelles écritures sont chiffrées
 * par {@see EncryptionListener}, cette commande rattrape l'historique.
 *
 * Idempotente : une ligne déjà chiffrée (enveloppe `{"_enc": …}`) est ignorée, on peut donc la
 * relancer sans risque (et la reprendre après une interruption). Écriture ligne à ligne, gardée
 * par la même condition que la sélection : une analyse réécrite entre-temps par un worker n'est
 * jamais écrasée.
 */
#[AsCommand(
    name: 'app:ocr:encrypt-stored-data',
    description: 'Chiffre les données OCR (ocrData, ocrFindings) encore stockées en clair.',
)]
final class EncryptStoredOcrDataCommand extends Command
{
    /**
     * Table => colonnes JSON concernées. Noms figés (jamais issus d'une entrée utilisateur).
     */
    private const array TARGETS = [
        'compliance_documents' => ['ocr_data', 'ocr_findings'],
        'kyc_documents' => ['ocr_data'],
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly MessageCipher $cipher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Compte les lignes concernées sans rien écrire.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $total = 0;

        foreach (self::TARGETS as $table => $columns) {
            foreach ($columns as $column) {
                $count = $this->encryptColumn($table, $column, $dryRun);
                $total += $count;
                $io->writeln(sprintf('%s.%s : %d ligne(s) %s.', $table, $column, $count, $dryRun ? 'à chiffrer' : 'chiffrée(s)'));
            }
        }

        $io->success(sprintf('%d valeur(s) %s.', $total, $dryRun ? 'à chiffrer (dry-run, rien écrit)' : 'chiffrée(s)'));

        return Command::SUCCESS;
    }

    private function encryptColumn(string $table, string $column, bool $dryRun): int
    {
        // jsonb_exists plutôt que l'opérateur `?` : celui-ci est pris pour un paramètre positionnel par DBAL.
        $notEncrypted = sprintf("%s IS NOT NULL AND NOT jsonb_exists(%s::jsonb, '%s')", $column, $column, EncryptionListener::ENVELOPE_KEY);

        if ($dryRun) {
            return (int) $this->connection->fetchOne(sprintf('SELECT COUNT(*) FROM %s WHERE %s', $table, $notEncrypted));
        }

        $done = 0;
        do {
            $rows = $this->connection->fetchAllAssociative(
                sprintf('SELECT id, %s AS payload FROM %s WHERE %s ORDER BY id LIMIT 200', $column, $table, $notEncrypted),
            );

            $updatedInBatch = 0;

            foreach ($rows as $row) {
                $envelope = json_encode(
                    [EncryptionListener::ENVELOPE_KEY => $this->cipher->encrypt((string) $row['payload'])],
                    \JSON_THROW_ON_ERROR,
                );

                $updatedInBatch += (int) $this->connection->executeStatement(
                    sprintf('UPDATE %s SET %s = CAST(:envelope AS json) WHERE id = :id AND %s', $table, $column, $notEncrypted),
                    ['envelope' => $envelope, 'id' => $row['id']],
                );
            }

            $done += $updatedInBatch;
            // Garde-fou : un lot qui n'avance pas ne doit jamais faire boucler la commande.
        } while ([] !== $rows && $updatedInBatch > 0);

        return $done;
    }
}
