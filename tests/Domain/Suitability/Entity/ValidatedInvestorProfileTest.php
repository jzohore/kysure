<?php

declare(strict_types=1);

namespace App\Tests\Domain\Suitability\Entity;

use App\Domain\Suitability\Entity\InvestorProfileAssessment;
use App\Domain\Suitability\Entity\ValidatedInvestorProfile;
use App\Domain\User\Entity\Client;
use App\Domain\User\Entity\User;
use App\Domain\Workspace\Entity\Workspace;
use App\Tests\Application\ReflectionHelperTrait;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class ValidatedInvestorProfileTest extends TestCase
{
    use ReflectionHelperTrait;

    private Workspace $workspace;
    private Client $client;
    private User $cgp;
    private InvestorProfileAssessment $assessment;

    protected function setUp(): void
    {
        $this->workspace = $this->createEntityState(Workspace::class, ['slugId' => 'wrk_1', 'name' => 'Cabinet']);
        $this->client = $this->createEntityState(Client::class, ['id' => Uuid::v7(), 'slugId' => 'cli_1', 'email' => 'jean@example.com']);
        $this->cgp = $this->createEntityState(User::class, ['id' => Uuid::v7(), 'firstName' => 'Marie', 'lastName' => 'Curie']);
        $this->assessment = InvestorProfileAssessment::create($this->workspace, $this->client);
    }

    private function content(): array
    {
        return ['answers' => [], 'scoreSnapshot' => ['finalProfile' => 4]];
    }

    public function testValidateFreezesTheContentAndComputesItsHash(): void
    {
        $profile = ValidatedInvestorProfile::validate(
            $this->workspace,
            $this->client,
            $this->assessment,
            $this->cgp,
            $this->content(),
            version: 1,
        );

        self::assertStringStartsWith('vip_', $profile->slugId);
        self::assertSame(1, $profile->version);
        self::assertTrue($profile->isInForce());
        self::assertFalse($profile->isRevoked());
        self::assertFalse($profile->isOverridden());
        self::assertSame(4, $profile->computedProfileLevel());
        self::assertSame(4, $profile->retainedProfileLevel());
        self::assertTrue($profile->matchesStoredHash());
    }

    public function testOverriddenProfileTakesPrecedenceOverTheComputedOne(): void
    {
        $profile = ValidatedInvestorProfile::validate(
            $this->workspace,
            $this->client,
            $this->assessment,
            $this->cgp,
            $this->content(),
            version: 1,
            overriddenProfileLevel: 2,
            overrideReason: 'Capacité à subir des pertes visiblement sous-estimée par le client.',
        );

        self::assertTrue($profile->isOverridden());
        self::assertSame(4, $profile->computedProfileLevel(), 'La proposition initiale du moteur ne doit jamais être perdue.');
        self::assertSame(2, $profile->retainedProfileLevel());
    }

    public function testOverridingRequiresAValueWithinOneToSeven(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ValidatedInvestorProfile::validate(
            $this->workspace,
            $this->client,
            $this->assessment,
            $this->cgp,
            $this->content(),
            version: 1,
            overriddenProfileLevel: 8,
            overrideReason: 'Motif',
        );
    }

    public function testOverridingRequiresANonEmptyReason(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ValidatedInvestorProfile::validate(
            $this->workspace,
            $this->client,
            $this->assessment,
            $this->cgp,
            $this->content(),
            version: 1,
            overriddenProfileLevel: 3,
            overrideReason: '   ',
        );
    }

    public function testRevokeRequiresANonEmptyReason(): void
    {
        $profile = ValidatedInvestorProfile::validate($this->workspace, $this->client, $this->assessment, $this->cgp, $this->content(), version: 1);

        $this->expectException(\DomainException::class);

        $profile->revoke($this->cgp, '   ');
    }

    public function testRevokeCannotBeAppliedTwice(): void
    {
        $profile = ValidatedInvestorProfile::validate($this->workspace, $this->client, $this->assessment, $this->cgp, $this->content(), version: 1);
        $profile->revoke($this->cgp, 'Nouvelle version après correction.');

        $this->expectException(\DomainException::class);

        $profile->revoke($this->cgp, 'Autre motif.');
    }

    public function testRevokeMarksTheProfileAsOutOfForce(): void
    {
        $profile = ValidatedInvestorProfile::validate($this->workspace, $this->client, $this->assessment, $this->cgp, $this->content(), version: 1);

        $profile->revoke($this->cgp, 'Nouvelle version après correction.');

        self::assertTrue($profile->isRevoked());
        self::assertFalse($profile->isInForce());
        self::assertSame('Nouvelle version après correction.', $profile->revokeReason);
        self::assertSame($this->cgp->getFullName(), $profile->revokedByName);
    }
}
