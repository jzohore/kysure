<?php

declare(strict_types=1);

namespace App\Tests\Domain\Compliance\Entity;

use App\Domain\Compliance\Entity\IndividualFolder;
use App\Domain\Compliance\Enum\ComplianceFolderStatus;
use App\Domain\Compliance\Enum\RiskLevel;
use App\Domain\Compliance\Exception\FolderStateException;
use App\Domain\User\Entity\User;
use App\Domain\Workspace\Entity\Workspace;
use App\Tests\Application\ReflectionHelperTrait;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class ComplianceFolderApprovalTest extends TestCase
{
    use ReflectionHelperTrait;

    private function folder(ComplianceFolderStatus $status): IndividualFolder
    {
        $workspace = $this->createEntityState(Workspace::class, ['slugId' => 'wrk_1', 'name' => 'Cabinet A']);

        return $this->createEntityState(IndividualFolder::class, [
            'workspace' => $workspace,
            'reference' => 'DOS-2026-001',
            'status' => $status,
            'history' => [],
            'createdAt' => new \DateTimeImmutable('2026-01-01'),
        ]);
    }

    private function reviewer(): User
    {
        return $this->createEntityState(User::class, ['id' => Uuid::v7(), 'firstName' => 'Marie', 'lastName' => 'Curie']);
    }

    public function testApproveFreezesTheStatusAndTheRiskLevel(): void
    {
        $folder = $this->folder(ComplianceFolderStatus::IN_REVIEW);

        $folder->approve(RiskLevel::MEDIUM, $this->reviewer(), 'Dossier complet.');

        self::assertSame(ComplianceFolderStatus::APPROVED, $folder->status);
        self::assertSame(RiskLevel::MEDIUM, $folder->riskLevel);
        self::assertTrue($folder->isCertified);
    }

    public function testApproveRejectsAFolderNotInReview(): void
    {
        $folder = $this->folder(ComplianceFolderStatus::DRAFT);

        $this->expectException(FolderStateException::class);
        $folder->approve(RiskLevel::LOW, $this->reviewer());
    }

    public function testRejectSetsTheStatusWhenInReview(): void
    {
        $folder = $this->folder(ComplianceFolderStatus::IN_REVIEW);

        $folder->reject('Pièces incohérentes.', $this->reviewer());

        self::assertSame(ComplianceFolderStatus::REJECTED, $folder->status);
    }

    public function testRejectRefusesAFolderNotInReview(): void
    {
        $folder = $this->folder(ComplianceFolderStatus::DRAFT);

        $this->expectException(FolderStateException::class);
        $folder->reject('Motif.', $this->reviewer());
    }
}
