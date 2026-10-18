<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Security\Antivirus;

use App\Domain\Compliance\Exception\Document\MalwareDetectedException;
use App\Domain\Compliance\Exception\Document\VirusScannerUnavailableException;
use App\Infrastructure\Security\Antivirus\ClamAvScanner;
use App\Infrastructure\Security\Antivirus\NullVirusScanner;
use App\Infrastructure\Security\Antivirus\VirusScannerFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ClamAvScannerTest extends TestCase
{
    public function testACleanReplyPasses(): void
    {
        ClamAvScanner::interpret("stream: OK\0");

        $this->expectNotToPerformAssertions();
    }

    public function testAnInfectedReplyRaisesTheDetectedSignature(): void
    {
        try {
            ClamAvScanner::interpret("stream: Win.Test.EICAR_HDB-1 FOUND\0");
            self::fail('Un fichier infecté doit lever une exception.');
        } catch (MalwareDetectedException $exception) {
            self::assertSame('Win.Test.EICAR_HDB-1', $exception->signature);
            self::assertStringNotContainsString('EICAR', $exception->getMessage(), 'La signature n\'est pas exposée au client.');
        }
    }

    #[DataProvider('unusableReplies')]
    public function testAnyOtherReplyIsTreatedAsAFailedScan(string $reply): void
    {
        $this->expectException(VirusScannerUnavailableException::class);

        ClamAvScanner::interpret($reply);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unusableReplies(): iterable
    {
        yield 'réponse vide (connexion coupée)' => [''];
        yield 'erreur clamd' => ["INSTREAM size limit exceeded. ERROR\0"];
        yield 'erreur de flux' => ["stream: Can't allocate memory ERROR\0"];
        yield 'texte inattendu' => ['pong'];
    }

    public function testAnUnreachableDaemonRefusesTheFileInsteadOfLettingItThrough(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'av_');
        file_put_contents($file, 'contenu');

        try {
            // Port 1 : rien n'écoute.
            $scanner = new ClamAvScanner('127.0.0.1', 1, new NullLogger());

            $this->expectException(VirusScannerUnavailableException::class);
            $scanner->scan($file);
        } finally {
            unlink($file);
        }
    }

    public function testOnlyAnExplicitDisabledModeTurnsTheScanOff(): void
    {
        $clamAv = new ClamAvScanner('clamav', 3310, new NullLogger());
        $null = new NullVirusScanner(new NullLogger());
        $factory = new VirusScannerFactory($clamAv, $null);

        self::assertSame($null, $factory->create('disabled'));
        self::assertSame($clamAv, $factory->create('clamav'));
        self::assertSame($clamAv, $factory->create('change_me_virus_scan_mode'), 'Valeur inconnue : on garde l\'antivirus.');
        self::assertSame($clamAv, $factory->create(''));
    }
}
