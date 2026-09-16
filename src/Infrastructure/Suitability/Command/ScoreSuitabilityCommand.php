<?php

declare(strict_types=1);

namespace App\Infrastructure\Suitability\Command;

use App\Domain\Suitability\Enum\InvestmentHorizon;
use App\Domain\Suitability\Enum\KnowledgeLevel;
use App\Domain\Suitability\Enum\LossReaction;
use App\Domain\Suitability\Enum\ProductFamily;
use App\Domain\Suitability\Enum\SustainabilityPreference;
use App\Domain\Suitability\Enum\TransactionFrequency;
use App\Domain\Suitability\Service\SuitabilityScoringEngineInterface;
use App\Domain\Suitability\ValueObject\ExperienceAnswers;
use App\Domain\Suitability\ValueObject\FinancialKnowledgeAnswers;
use App\Domain\Suitability\ValueObject\LossCapacityInputs;
use App\Domain\Suitability\ValueObject\RiskToleranceAnswers;
use App\Domain\Suitability\ValueObject\SuitabilityAssessmentInput;
use App\Domain\Suitability\ValueObject\SustainabilityAnswers;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Outil de calibration du moteur de notation du profil investisseur (lot 0, issue #23) :
 * prend un JSON de réponses en entrée, restitue le profil calculé et le détail des scores.
 * Aucune persistance — c'est un banc d'essai pour ajuster les pondérations avec le CGP sur
 * des cas types, avant qu'un questionnaire ou un écran n'existe.
 */
#[AsCommand(
    name: 'app:suitability:score',
    description: 'Calcule un profil investisseur à partir d\'un fichier JSON de réponses (outil de calibration).',
)]
final readonly class ScoreSuitabilityCommand
{
    public function __construct(
        private SuitabilityScoringEngineInterface $scoringEngine,
    ) {
    }

    public function __invoke(
        InputInterface $input,
        OutputInterface $output,
        #[Argument(description: 'Chemin du fichier JSON de réponses (voir --sample pour le format attendu).')]
        ?string $file = null,
        #[Option(description: 'Affiche un exemple de fichier JSON de réponses et quitte.')]
        bool $sample = false,
    ): int {
        $io = new SymfonyStyle($input, $output);

        if ($sample) {
            $output->writeln(json_encode($this->sampleAnswers(), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR));

            return Command::SUCCESS;
        }

        if (null === $file || '' === $file) {
            $io->error('Indiquez le chemin d\'un fichier JSON de réponses, ou utilisez --sample pour voir le format attendu.');

            return Command::INVALID;
        }

        try {
            $assessmentInput = $this->buildInput($this->decodeFile($file));
        } catch (\InvalidArgumentException|\JsonException $e) {
            $io->error(sprintf('Fichier de réponses invalide : %s', $e->getMessage()));

            return Command::FAILURE;
        }

        $result = $this->scoringEngine->score($assessmentInput);

        $io->title(sprintf('Profil investisseur — moteur %s', $result->engineVersion));
        $io->table(
            ['Dimension', 'Score (échelle 1-7)'],
            [
                ['Connaissances financières', sprintf('%.1f', $result->knowledgeScore)],
                ['Expérience d\'investissement', sprintf('%.1f', $result->experienceScore)],
                ['Tolérance au risque', sprintf('%.1f', $result->toleranceScore)],
                ['Capacité à subir des pertes', sprintf('%d — %s', $result->capacityLevel->value, $result->capacityLevel->getLabel())],
            ],
        );

        $io->section(sprintf(
            'Profil retenu : %d — %s%s',
            $result->finalProfile->value,
            $result->finalProfile->getLabel(),
            $result->cappedByCapacity ? ' (plafonné par la capacité)' : '',
        ));

        $io->listing($result->explanationFactors);

        return Command::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeFile(string $file): array
    {
        if (!is_readable($file)) {
            throw new \InvalidArgumentException(sprintf('Le fichier "%s" est introuvable ou illisible.', $file));
        }

        $content = (string) file_get_contents($file);
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function buildInput(array $data): SuitabilityAssessmentInput
    {
        return SuitabilityAssessmentInput::fromAnswers(
            knowledge: $this->buildKnowledge($this->section($data, 'knowledge')),
            experience: $this->buildExperience($this->section($data, 'experience')),
            tolerance: $this->buildTolerance($this->section($data, 'tolerance')),
            capacity: $this->buildCapacity($this->section($data, 'capacity')),
            sustainability: $this->buildSustainability($this->section($data, 'sustainability')),
        );
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function section(array $data, string $key): array
    {
        if (!isset($data[$key]) || !\is_array($data[$key])) {
            throw new \InvalidArgumentException(sprintf('Section "%s" manquante ou invalide.', $key));
        }

        /** @var array<string, mixed> $section */
        $section = $data[$key];

        return $section;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function buildKnowledge(array $data): FinancialKnowledgeAnswers
    {
        $levels = [];
        foreach ($data as $familyValue => $levelValue) {
            $levels[(string) $familyValue] = KnowledgeLevel::from($this->asInt($levelValue, "knowledge.{$familyValue}"));
        }

        return FinancialKnowledgeAnswers::fromLevels($levels);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function buildExperience(array $data): ExperienceAnswers
    {
        /** @var list<mixed> $productsHeldRaw */
        $productsHeldRaw = \is_array($data['productsHeld'] ?? null) ? $data['productsHeld'] : [];

        return ExperienceAnswers::fromAnswers(
            productsHeld: array_map(
                static fn (mixed $value): ProductFamily => ProductFamily::from((string) $value),
                $productsHeldRaw,
            ),
            transactionFrequency: TransactionFrequency::from($this->asInt($data['transactionFrequency'] ?? null, 'experience.transactionFrequency')),
            approximateAmount: $this->asFloat($data['approximateAmount'] ?? null, 'experience.approximateAmount'),
            experienceYears: $this->asInt($data['experienceYears'] ?? null, 'experience.experienceYears'),
            approximateTransactionCount: $this->asInt($data['approximateTransactionCount'] ?? null, 'experience.approximateTransactionCount'),
            hasExperiencedLosses: (bool) ($data['hasExperiencedLosses'] ?? false),
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function buildTolerance(array $data): RiskToleranceAnswers
    {
        return RiskToleranceAnswers::fromReactions(
            reactionToMinus10Percent: LossReaction::from($this->asInt($data['reactionToMinus10Percent'] ?? null, 'tolerance.reactionToMinus10Percent')),
            reactionToMinus20Percent: LossReaction::from($this->asInt($data['reactionToMinus20Percent'] ?? null, 'tolerance.reactionToMinus20Percent')),
            reactionToSignificantLoss: LossReaction::from($this->asInt($data['reactionToSignificantLoss'] ?? null, 'tolerance.reactionToSignificantLoss')),
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function buildCapacity(array $data): LossCapacityInputs
    {
        return LossCapacityInputs::fromInputs(
            annualIncome: $this->asFloat($data['annualIncome'] ?? null, 'capacity.annualIncome'),
            annualExpenses: $this->asFloat($data['annualExpenses'] ?? null, 'capacity.annualExpenses'),
            netWorth: $this->asFloat($data['netWorth'] ?? null, 'capacity.netWorth'),
            availableLiquidity: $this->asFloat($data['availableLiquidity'] ?? null, 'capacity.availableLiquidity'),
            amountToInvest: $this->asFloat($data['amountToInvest'] ?? null, 'capacity.amountToInvest'),
            horizon: InvestmentHorizon::from($this->asString($data['horizon'] ?? null, 'capacity.horizon')),
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function buildSustainability(array $data): SustainabilityAnswers
    {
        $constraints = $data['specificConstraints'] ?? null;

        return SustainabilityAnswers::fromAnswers(
            preference: SustainabilityPreference::from($this->asString($data['preference'] ?? null, 'sustainability.preference')),
            specificConstraints: null !== $constraints ? (string) $constraints : null,
        );
    }

    private function asInt(mixed $value, string $field): int
    {
        if (!\is_int($value)) {
            throw new \InvalidArgumentException(sprintf('Champ "%s" : nombre entier attendu.', $field));
        }

        return $value;
    }

    private function asFloat(mixed $value, string $field): float
    {
        if (!\is_int($value) && !\is_float($value)) {
            throw new \InvalidArgumentException(sprintf('Champ "%s" : nombre attendu.', $field));
        }

        return (float) $value;
    }

    private function asString(mixed $value, string $field): string
    {
        if (!\is_string($value) || '' === $value) {
            throw new \InvalidArgumentException(sprintf('Champ "%s" : chaîne non vide attendue.', $field));
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function sampleAnswers(): array
    {
        return [
            'knowledge' => [
                'opcvm_etf' => KnowledgeLevel::BONNE->value,
                'titres_vifs' => KnowledgeLevel::LIMITEE->value,
                'assurance_vie' => KnowledgeLevel::EXPERTISE->value,
                'immobilier_scpi' => KnowledgeLevel::INTERMEDIAIRE->value,
                'produits_complexes' => KnowledgeLevel::AUCUNE->value,
            ],
            'experience' => [
                'productsHeld' => [ProductFamily::OPCVM_ETF->value, ProductFamily::ASSURANCE_VIE->value],
                'transactionFrequency' => TransactionFrequency::REGULIERE->value,
                'approximateAmount' => 50000,
                'experienceYears' => 8,
                'approximateTransactionCount' => 40,
                'hasExperiencedLosses' => true,
            ],
            'tolerance' => [
                'reactionToMinus10Percent' => LossReaction::NE_FAIT_RIEN->value,
                'reactionToMinus20Percent' => LossReaction::REDUIT_LA_POSITION->value,
                'reactionToSignificantLoss' => LossReaction::VEND_TOUT->value,
            ],
            'capacity' => [
                'annualIncome' => 60000,
                'annualExpenses' => 35000,
                'netWorth' => 250000,
                'availableLiquidity' => 40000,
                'amountToInvest' => 20000,
                'horizon' => InvestmentHorizon::DE_5_A_8_ANS->value,
            ],
            'sustainability' => [
                'preference' => SustainabilityPreference::SANS_PREFERENCE->value,
                'specificConstraints' => null,
            ],
        ];
    }
}
