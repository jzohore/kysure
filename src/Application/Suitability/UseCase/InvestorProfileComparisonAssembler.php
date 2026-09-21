<?php

declare(strict_types=1);

namespace App\Application\Suitability\UseCase;

use App\Application\Suitability\DTO\Response\InvestorProfileComparisonAnswerResponse;
use App\Application\Suitability\DTO\Response\InvestorProfileComparisonDimensionResponse;
use App\Application\Suitability\DTO\Response\InvestorProfileComparisonResponse;
use App\Domain\Suitability\Entity\ValidatedInvestorProfile;
use App\Domain\Suitability\Enum\AssessmentDimension;
use App\Domain\Suitability\Enum\InvestorProfileLevel;
use App\Domain\Suitability\Enum\QuestionKey;

/**
 * Comparatif visuel entre deux versions d'un profil investisseur validé du même cabinet (cas
 * typique : révocation puis revalidation après un changement de situation du client) — la
 * réponse à une question, avant/après, avec les différences mises en évidence. Réutilise le
 * formatage de {@see InvestorProfileSynthesisAssembler} pour rester cohérent avec le PDF de
 * synthèse (même libellé pour la même valeur brute).
 */
class InvestorProfileComparisonAssembler
{
    public function __construct(
        private readonly InvestorProfileSynthesisAssembler $synthesisAssembler,
    ) {
    }

    public function assemble(ValidatedInvestorProfile $older, ValidatedInvestorProfile $newer): InvestorProfileComparisonResponse
    {
        /** @var array<string, mixed> $oldAnswers */
        $oldAnswers = $older->content['answers'] ?? [];
        /** @var array<string, mixed> $newAnswers */
        $newAnswers = $newer->content['answers'] ?? [];

        $dimensions = array_map(
            fn (AssessmentDimension $dimension): InvestorProfileComparisonDimensionResponse => new InvestorProfileComparisonDimensionResponse(
                label: $dimension->getLabel(),
                answers: $this->compareDimensionAnswers($dimension, $oldAnswers, $newAnswers),
            ),
            AssessmentDimension::cases(),
        );

        $oldLevel = $older->retainedProfileLevel();
        $newLevel = $newer->retainedProfileLevel();

        return new InvestorProfileComparisonResponse(
            oldVersion: $older->version,
            newVersion: $newer->version,
            oldValidatedAt: $older->validatedAt,
            newValidatedAt: $newer->validatedAt,
            oldRetainedLevel: $oldLevel,
            newRetainedLevel: $newLevel,
            oldRetainedLabel: InvestorProfileLevel::from($oldLevel)->getLabel(),
            newRetainedLabel: InvestorProfileLevel::from($newLevel)->getLabel(),
            levelChanged: $oldLevel !== $newLevel,
            dimensions: $dimensions,
        );
    }

    /**
     * @param array<string, mixed> $oldAnswers
     * @param array<string, mixed> $newAnswers
     *
     * @return list<InvestorProfileComparisonAnswerResponse>
     */
    private function compareDimensionAnswers(AssessmentDimension $dimension, array $oldAnswers, array $newAnswers): array
    {
        $result = [];

        foreach (QuestionKey::forDimension($dimension) as $key) {
            $hasOld = \array_key_exists($key->value, $oldAnswers);
            $hasNew = \array_key_exists($key->value, $newAnswers);

            if (!$hasOld && !$hasNew) {
                continue;
            }

            $oldLabel = $hasOld ? $this->synthesisAssembler->formatAnswer($key, $oldAnswers[$key->value]) : 'Non renseigné';
            $newLabel = $hasNew ? $this->synthesisAssembler->formatAnswer($key, $newAnswers[$key->value]) : 'Non renseigné';

            $result[] = new InvestorProfileComparisonAnswerResponse(
                questionLabel: $key->getLabel(),
                oldAnswerLabel: $oldLabel,
                newAnswerLabel: $newLabel,
                changed: $oldLabel !== $newLabel,
            );
        }

        return $result;
    }
}
