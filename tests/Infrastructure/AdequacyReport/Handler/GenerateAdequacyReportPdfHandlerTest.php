<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\AdequacyReport\Handler;

use App\Application\AdequacyReport\UseCase\AdequacyReportDocumentAssembler;
use App\Domain\AdequacyReport\Entity\AdequacyReport;
use App\Domain\AdequacyReport\Repository\AdequacyReportRepositoryInterface;
use App\Domain\Port\DocumentStorageInterface;
use App\Infrastructure\AdequacyReport\Handler\GenerateAdequacyReportPdfHandler;
use App\Infrastructure\AdequacyReport\Message\GenerateAdequacyReportPdfMessage;
use App\Infrastructure\Pdf\PdfGeneratorInterface;
use App\Tests\Domain\AdequacyReport\AdequacyReportFixturesTrait;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

final class GenerateAdequacyReportPdfHandlerTest extends TestCase
{
    use AdequacyReportFixturesTrait;

    private const string PDF = "%PDF-1.7\nrapport";

    private AdequacyReport $report;
    private AdequacyReportRepositoryInterface&MockObject $reportRepository;
    private PdfGeneratorInterface&MockObject $pdfGenerator;
    private DocumentStorageInterface&MockObject $storage;
    private GenerateAdequacyReportPdfHandler $handler;

    protected function setUp(): void
    {
        $this->setUpAdequacyFixtures();
        $this->report = $this->validateReport();

        $this->reportRepository = $this->createMock(AdequacyReportRepositoryInterface::class);
        $this->reportRepository->method('findBySlugId')->willReturn($this->report);
        $this->pdfGenerator = $this->createMock(PdfGeneratorInterface::class);
        $this->storage = $this->createMock(DocumentStorageInterface::class);

        $this->handler = new GenerateAdequacyReportPdfHandler(
            $this->reportRepository,
            new AdequacyReportDocumentAssembler(),
            $this->pdfGenerator,
            $this->storage,
            new NullLogger(),
        );
    }

    public function testGeneratesStoresAndRecordsThePdf(): void
    {
        $this->pdfGenerator->expects(self::once())->method('generateFromHtml')
            ->with('@app/pdf/adequacy_report_template.html.twig', self::callback(static fn (array $context): bool => true === $context['is_pdf']))
            ->willReturn(self::PDF);
        $this->storage->expects(self::once())->method('store')
            ->with(self::anything(), 'documents/adequacy-report/wrk_1/fld_1')
            ->willReturn('documents/adequacy-report/wrk_1/fld_1/rapport.pdf');
        $this->reportRepository->expects(self::once())->method('save')->with($this->report);

        ($this->handler)(new GenerateAdequacyReportPdfMessage($this->report->slugId, $this->report->contentHash));

        self::assertSame('documents/adequacy-report/wrk_1/fld_1/rapport.pdf', $this->report->pdfStoragePath);
    }

    public function testReplayDoesNotRegenerateAnExistingPdf(): void
    {
        $this->report->markPdfGenerated('documents/deja-la.pdf');

        $this->pdfGenerator->expects(self::never())->method('generateFromHtml');
        $this->storage->expects(self::never())->method('store');
        $this->reportRepository->expects(self::never())->method('save');

        ($this->handler)(new GenerateAdequacyReportPdfMessage($this->report->slugId, $this->report->contentHash));
    }

    public function testPdfIsNeverGeneratedForAHashOtherThanTheValidatedOne(): void
    {
        $this->pdfGenerator->expects(self::never())->method('generateFromHtml');
        $this->storage->expects(self::never())->method('store');
        $this->reportRepository->expects(self::never())->method('save');

        $this->expectException(UnrecoverableMessageHandlingException::class);

        ($this->handler)(new GenerateAdequacyReportPdfMessage($this->report->slugId, str_repeat('0', 64)));
    }

    public function testPdfIsNeverGeneratedForATamperedReport(): void
    {
        $content = $this->report->content;
        $content['advisorRationale'] = 'Justification réécrite en base.';
        new \ReflectionProperty(AdequacyReport::class, 'content')->setValue($this->report, $content);

        $this->pdfGenerator->expects(self::never())->method('generateFromHtml');
        $this->storage->expects(self::never())->method('store');
        $this->reportRepository->expects(self::never())->method('save');

        $this->expectException(UnrecoverableMessageHandlingException::class);

        ($this->handler)(new GenerateAdequacyReportPdfMessage($this->report->slugId, $this->report->contentHash));
    }

    public function testInvalidPdfTriggersARetryWithoutRecordingAnything(): void
    {
        $this->pdfGenerator->expects(self::once())->method('generateFromHtml')->willReturn('<html>erreur</html>');
        $this->storage->expects(self::never())->method('store');
        $this->reportRepository->expects(self::never())->method('save');

        $this->expectException(\RuntimeException::class);

        ($this->handler)(new GenerateAdequacyReportPdfMessage($this->report->slugId, $this->report->contentHash));
    }
}
