<?php

declare(strict_types=1);

namespace App\Tests\Domain\AdequacyReport\Entity;

use App\Domain\AdequacyReport\Entity\ProductAdequacyCheck;
use App\Domain\AdequacyReport\Enum\MatchSeverity;
use App\Domain\AdequacyReport\Service\SuitabilityMatchEvaluator;
use App\Domain\AdequacyReport\ValueObject\AdequacyCheckInput;
use App\Domain\Compliance\Entity\ComplianceFolder;
use App\Domain\Compliance\Entity\IndividualFolder;
use App\Domain\ProductCatalogue\Entity\FinancialProduct;
use App\Domain\Suitability\Entity\ValidatedInvestorProfile;
use App\Domain\User\Entity\User;
use App\Domain\Workspace\Entity\Workspace;
use App\Tests\Application\ReflectionHelperTrait;
use App\Tests\Domain\AdequacyReport\AdequacyCheckInputFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class ProductAdequacyCheckTest extends TestCase
{
    use ReflectionHelperTrait;

    private Workspace $workspace;
    private ComplianceFolder $folder;
    private User $cgp;

    protected function setUp(): void
    {
        $this->workspace = $this->createEntityState(Workspace::class, ['slugId' => 'wrk_1', 'name' => 'Cabinet']);
        $this->folder = $this->createEntityState(IndividualFolder::class, ['slugId' => 'fld_1', 'workspace' => $this->workspace]);
        $this->cgp = $this->createEntityState(User::class, ['id' => Uuid::v7(), 'firstName' => 'Marie', 'lastName' => 'Curie']);
    }

    public function testCompliantCheckIsSealedWithItsInputsFindingsAndEngineVersion(): void
    {
        $input = AdequacyCheckInputFactory::create();

        $check = $this->record($input, justification: '  ');

        self::assertSame(MatchSeverity::CONFORME, $check->worstSeverity);
        self::assertNull($check->justification);
        self::assertSame(2_000_000, $check->amountInCents);
        self::assertSame(SuitabilityMatchEvaluator::VERSION, $check->content['engineVersion']);
        self::assertSame('ETF MSCI World', $check->productNameAtCheck());
        self::assertSame($this->cgp->getFullName(), $check->checkedByName);
        self::assertStringStartsWith('adq_', $check->slugId);
        self::assertTrue($check->matchesStoredHash());
        self::assertCount(8, $check->evaluation()->findings);
    }

    public function testBlockingFindingRequiresAWrittenJustification(): void
    {
        $input = AdequacyCheckInputFactory::create(sriLevel: 7);

        $this->expectException(\DomainException::class);

        $this->record($input, justification: 'Trop court');
    }

    public function testBlockingFindingIsRecordedOnceJustified(): void
    {
        $input = AdequacyCheckInputFactory::create(sriLevel: 7);

        $check = $this->record($input, justification: 'Client averti par écrit, exposition limitée à une poche satellite.');

        self::assertTrue($check->hasBlockingFinding());
        self::assertSame('Client averti par écrit, exposition limitée à une poche satellite.', $check->justification);
    }

    public function testRevokedProfileCannotBeUsed(): void
    {
        $profile = $this->createEntityState(ValidatedInvestorProfile::class, ['workspace' => $this->workspace, 'revokedAt' => new \DateTimeImmutable()]);

        $this->expectException(\DomainException::class);

        $this->record(AdequacyCheckInputFactory::create(), justification: null, profile: $profile);
    }

    public function testProductFromAnotherCabinetIsRejected(): void
    {
        $otherWorkspace = $this->createEntityState(Workspace::class, ['slugId' => 'wrk_2', 'name' => 'Concurrent']);
        $product = $this->createEntityState(FinancialProduct::class, ['workspace' => $otherWorkspace, 'name' => 'ETF MSCI World']);

        $this->expectException(\DomainException::class);

        $this->record(AdequacyCheckInputFactory::create(), justification: null, product: $product);
    }

    public function testTamperedContentNoLongerMatchesTheStoredHash(): void
    {
        $check = $this->record(AdequacyCheckInputFactory::create(), justification: null);

        $content = $check->content;
        $content['findings'][0]['message'] = 'Message altéré après coup.';
        new \ReflectionProperty(ProductAdequacyCheck::class, 'content')->setValue($check, $content);

        self::assertFalse($check->matchesStoredHash());
    }

    private function record(
        AdequacyCheckInput $input,
        ?string $justification,
        ?ValidatedInvestorProfile $profile = null,
        ?FinancialProduct $product = null,
    ): ProductAdequacyCheck {
        return ProductAdequacyCheck::record(
            complianceFolder: $this->folder,
            investorProfile: $profile ?? $this->createEntityState(ValidatedInvestorProfile::class, ['workspace' => $this->workspace, 'revokedAt' => null]),
            product: $product ?? $this->createEntityState(FinancialProduct::class, ['workspace' => $this->workspace, 'name' => 'ETF MSCI World']),
            checkedBy: $this->cgp,
            input: $input,
            evaluation: new SuitabilityMatchEvaluator()->evaluate($input),
            justification: $justification,
        );
    }
}
