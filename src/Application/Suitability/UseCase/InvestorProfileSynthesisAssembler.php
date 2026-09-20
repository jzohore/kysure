<?php

declare(strict_types=1);

namespace App\Application\Suitability\UseCase;

use App\Application\Suitability\DTO\Response\InvestorProfileSynthesisAnswerResponse;
use App\Application\Suitability\DTO\Response\InvestorProfileSynthesisDimensionResponse;
use App\Application\Suitability\DTO\Response\InvestorProfileSynthesisResponse;
use App\Domain\Suitability\Entity\ValidatedInvestorProfile;
use App\Domain\Suitability\Enum\AssessmentAnswerType;
use App\Domain\Suitability\Enum\AssessmentDimension;
use App\Domain\Suitability\Enum\InvestorProfileLevel;
use App\Domain\Suitability\Enum\QuestionKey;

/**
 * Prépare les données de présentation du PDF de synthèse (déclaration d'adéquation) à partir
 * d'un {@see ValidatedInvestorProfile} : traduit chaque réponse brute stockée en libellé
 * lisible (la logique de formatage quitte le template Twig). Pattern calqué sur
 * {@see \App\Application\Compliance\UseCase\ComplianceFolder\ComplianceFolderShowAssembler}.
 */
class InvestorProfileSynthesisAssembler
{
    public function assemble(ValidatedInvestorProfile $profile): InvestorProfileSynthesisResponse
    {
        $workspace = $profile->workspace;
        $client = $profile->client;
        /** @var array<string, mixed> $answers */
        $answers = $profile->content['answers'] ?? [];

        $dimensions = array_map(
            fn (AssessmentDimension $dimension): InvestorProfileSynthesisDimensionResponse => new InvestorProfileSynthesisDimensionResponse(
                label: $dimension->getLabel(),
                answers: $this->assembleDimensionAnswers($dimension, $answers),
            ),
            AssessmentDimension::cases(),
        );

        return new InvestorProfileSynthesisResponse(
            clientFullName: trim($client->firstName . ' ' . $client->lastName),
            clientEmail: $client->email,
            workspaceName: $workspace->name,
            workspaceLegalName: $workspace->legalName,
            workspaceAddress: $workspace->address,
            workspaceSiret: $workspace->siret,
            workspaceLogoStoragePath: $workspace->regulatoryProfile?->logoStoragePath,
            version: $profile->version,
            retainedProfileLevel: $profile->retainedProfileLevel(),
            retainedProfileLabel: InvestorProfileLevel::from($profile->retainedProfileLevel())->getLabel(),
            computedProfileLevel: $profile->computedProfileLevel(),
            isOverridden: $profile->isOverridden(),
            overrideReason: $profile->overrideReason,
            validatedAt: $profile->validatedAt,
            validatedByName: $profile->validatedByName,
            contentHash: $profile->contentHash,
            dimensions: $dimensions,
        );
    }

    /**
     * @param array<string, mixed> $answers
     *
     * @return list<InvestorProfileSynthesisAnswerResponse>
     */
    private function assembleDimensionAnswers(AssessmentDimension $dimension, array $answers): array
    {
        $result = [];

        foreach (QuestionKey::forDimension($dimension) as $key) {
            if (!\array_key_exists($key->value, $answers)) {
                continue;
            }

            $result[] = new InvestorProfileSynthesisAnswerResponse(
                questionLabel: $key->getLabel(),
                answerLabel: $this->formatAnswer($key, $answers[$key->value]),
            );
        }

        return $result;
    }

    private function formatAnswer(QuestionKey $key, mixed $value): string
    {
        $choices = $key->choices();

        if (null !== $choices) {
            if (\is_array($value)) {
                return [] === $value
                    ? 'Aucun'
                    : implode(', ', array_map(static fn (mixed $v): string => $choices[$v] ?? (string) $v, $value));
            }

            return $choices[$value] ?? (string) $value;
        }

        return match ($key->answerType()) {
            AssessmentAnswerType::BOOLEAN => true === $value ? 'Oui' : 'Non',
            AssessmentAnswerType::DECIMAL => number_format((float) $value, 0, ',', ' ') . ' €',
            AssessmentAnswerType::INTEGER => (string) (int) $value,
            default => (string) $value,
        };
    }
}
