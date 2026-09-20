<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Suitability\Handler;

use App\Application\Suitability\DTO\Response\InvestorProfileSynthesisResponse;
use App\Application\Suitability\UseCase\InvestorProfileSynthesisAssembler;
use App\Domain\Port\DocumentStorageInterface;
use App\Domain\Suitability\Entity\InvestorProfileAssessment;
use App\Domain\Suitability\Entity\ValidatedInvestorProfile;
use App\Domain\Suitability\Repository\ValidatedInvestorProfileRepositoryInterface;
use App\Domain\User\Entity\Client;
use App\Domain\User\Entity\User;
use App\Domain\Workspace\Entity\Workspace;
use App\Infrastructure\Pdf\PdfGeneratorInterface;
use App\Infrastructure\Suitability\Handler\GenerateInvestorProfilePdfHandler;
use App\Infrastructure\Suitability\Message\GenerateInvestorProfilePdfMessage;
use App\Tests\Application\ReflectionHelperTrait;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Uid\Uuid;

final class GenerateInvestorProfilePdfHandlerTest extends TestCase
{
    use ReflectionHelperTrait;

    private const string PDF = "%PDF-1.7\nsynthese";

    private ValidatedInvestorProfileRepositoryInterface&MockObject $profileRepository;
    private PdfGeneratorInterface&Stub $pdfGenerator;
    private DocumentStorageInterface&MockObject $storage;
    private GenerateInvestorProfilePdfHandler $handler;

    protected function setUp(): void
    {
        $this->profileRepository = $this->createMock(ValidatedInvestorProfileRepositoryInterface::class);
        $this->pdfGenerator = $this->createStub(PdfGeneratorInterface::class);
        $this->storage = $this->createMock(DocumentStorageInterface::class);

        $assembler = $this->createStub(InvestorProfileSynthesisAssembler::class);
        $assembler->method('assemble')->willReturn($this->createStub(InvestorProfileSynthesisResponse::class));

        $this->handler = new GenerateInvestorProfilePdfHandler(
            $this->profileRepository,
            $assembler,
            $this->pdfGenerator,
            $this->storage,
            new NullLogger(),
        );
    }

    private function profile(): ValidatedInvestorProfile
    {
        $workspace = $this->createEntityState(Workspace::class, ['slugId' => 'wrk_1', 'name' => 'Cabinet A']);
        $client = $this->createEntityState(Client::class, ['id' => Uuid::v7(), 'slugId' => 'cli_1', 'email' => 'a@b.fr', 'firstName' => 'A', 'lastName' => 'B']);
        $cgp = $this->createEntityState(User::class, ['id' => Uuid::v7(), 'firstName' => 'Marie', 'lastName' => 'Curie']);
        $assessment = InvestorProfileAssessment::create($workspace, $client);

        return ValidatedInvestorProfile::validate(
            $workspace,
            $client,
            $assessment,
            $cgp,
            ['answers' => [], 'scoreSnapshot' => ['finalProfile' => 3]],
            version: 1,
        );
    }

    public function testGeneratesStoresAndMarksTheProfilePdfGenerated(): void
    {
        $profile = $this->profile();
        $this->profileRepository->method('findBySlugId')->willReturn($profile);
        $this->pdfGenerator->method('generateFromHtml')->willReturn(self::PDF);

        $this->storage->expects($this->once())->method('store')->willReturn('documents/investor-profile/wrk_1/cli_1/profil.pdf');
        $this->profileRepository->expects($this->once())->method('save')->with($profile);

        ($this->handler)(new GenerateInvestorProfilePdfMessage($profile->slugId));

        self::assertSame('documents/investor-profile/wrk_1/cli_1/profil.pdf', $profile->pdfStoragePath);
    }

    public function testIsIdempotentWhenThePdfWasAlreadyGenerated(): void
    {
        $profile = $this->profile();
        $profile->markPdfGenerated('documents/investor-profile/wrk_1/cli_1/already.pdf');
        $this->profileRepository->method('findBySlugId')->willReturn($profile);

        $this->profileRepository->expects($this->never())->method('save');
        $this->storage->expects($this->never())->method('store');

        ($this->handler)(new GenerateInvestorProfilePdfMessage($profile->slugId));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testRethrowsAndDoesNotSaveOnGenericFailure(): void
    {
        $profile = $this->profile();
        $this->profileRepository->method('findBySlugId')->willReturn($profile);
        $this->pdfGenerator->method('generateFromHtml')->willReturn('<html>oops</html>');

        $this->profileRepository->expects($this->never())->method('save');

        try {
            ($this->handler)(new GenerateInvestorProfilePdfMessage($profile->slugId));
            self::fail('Une exception aurait dû être levée.');
        } catch (\RuntimeException) {
            // attendu
        }

        self::assertNull($profile->pdfStoragePath);
    }
}
