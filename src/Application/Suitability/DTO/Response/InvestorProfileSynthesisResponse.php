<?php

declare(strict_types=1);

namespace App\Application\Suitability\DTO\Response;

readonly class InvestorProfileSynthesisResponse
{
    /**
     * @param list<InvestorProfileSynthesisDimensionResponse> $dimensions
     */
    public function __construct(
        public string $clientFullName,
        public string $clientEmail,
        public string $workspaceName,
        public ?string $workspaceLegalName,
        public ?string $workspaceAddress,
        public ?string $workspaceSiret,
        public ?string $workspaceLogoStoragePath,
        public int $version,
        public int $retainedProfileLevel,
        public string $retainedProfileLabel,
        public int $computedProfileLevel,
        public bool $isOverridden,
        public ?string $overrideReason,
        public \DateTimeImmutable $validatedAt,
        public string $validatedByName,
        public string $contentHash,
        public array $dimensions,
    ) {
    }
}
