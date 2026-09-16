<?php

declare(strict_types=1);

namespace App\Domain\Suitability\Entity;

use App\Domain\Suitability\Enum\AnswerSource;
use App\Domain\Suitability\Enum\AssessmentStatus;
use App\Domain\Suitability\Enum\QuestionKey;
use App\Domain\User\Entity\Client;
use App\Domain\Workspace\Entity\Workspace;
use App\Infrastructure\Trait\GenerateSlugPrefixedTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;

use function Symfony\Component\Clock\now;

use Symfony\Component\Uid\Uuid;
use Webmozart\Assert\Assert;

/**
 * Questionnaire profil investisseur d'un client (§3 du cahier des charges CIF Pilot),
 * auto-administré depuis l'espace client. Capture les réponses uniquement — le figeage/
 * validation par le CGP est un objet séparé (ValidatedInvestorProfile, lot 2), qui copiera
 * l'assessment soumis plutôt que de le référencer, pour rester lisible même si celui-ci
 * évolue par la suite (ex: nouvelle tentative après correction).
 *
 * Chaque réponse porte son propre horodatage/auteur/source ({@see self::recordAnswer()}) :
 * c'est ce qui rend le dossier défendable en audit et ce qui permettra, au lot 5, à l'IA de
 * pré-remplir certaines réponses sans confusion avec une réponse actée par le client.
 */
#[ORM\Entity]
#[ORM\Table(name: 'investor_profile_assessments')]
#[ORM\Index(name: 'idx_investor_profile_assessments_client_status', columns: ['client_id', 'status'])]
class InvestorProfileAssessment
{
    use GenerateSlugPrefixedTrait;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    public ?Uuid $id = null {
        get => $this->id;
    }

    #[ORM\Column(type: Types::STRING, length: 255, unique: true)]
    public private(set) string $slugId;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    public private(set) \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::STRING, enumType: AssessmentStatus::class)]
    public private(set) AssessmentStatus $status = AssessmentStatus::DRAFT;

    /**
     * Réponses indexées par {@see QuestionKey::value}, chacune sous la forme
     * `{value, answeredAt (ISO 8601), answeredBy (uuid ou null), source}`.
     *
     * @var array<string, array{value: mixed, answeredAt: string, answeredBy: ?string, source: string}>
     */
    #[ORM\Column(type: Types::JSON)]
    public private(set) array $answers = [];

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public private(set) ?\DateTimeImmutable $submittedAt = null;

    /**
     * Instantané du résultat du moteur de scoring au moment de la soumission (voir
     * {@see \App\Domain\Suitability\ValueObject\SuitabilityScoreResult}) : pas encore un
     * profil opposable, juste la proposition que le CGP examinera au lot 2.
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    public private(set) ?array $scoreSnapshot = null;

    private function __construct(
        #[ORM\ManyToOne(targetEntity: Workspace::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public private(set) Workspace $workspace,
        #[ORM\ManyToOne(targetEntity: Client::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public private(set) Client $client,
    ) {
        $this->createdAt = now();
        $this->slugId = $this->generate_ulid_prefixed('ipa_');
    }

    public static function create(Workspace $workspace, Client $client): self
    {
        return new self($workspace, $client);
    }

    /**
     * Enregistre ou remplace une réponse. Idempotent (rejouer la même réponse ne crée pas
     * de doublon) ; interdit une fois l'assessment soumis, pour ne pas modifier en place ce
     * que le CGP a pu commencer à examiner.
     */
    public function recordAnswer(QuestionKey $key, mixed $value, AnswerSource $source, ?Uuid $answeredBy): void
    {
        if (AssessmentStatus::SUBMITTED === $this->status) {
            throw new \DomainException('Impossible de modifier une réponse : le questionnaire a déjà été soumis.');
        }

        $this->answers[$key->value] = [
            'value' => $value,
            'answeredAt' => now()->format(\DateTimeInterface::ATOM),
            'answeredBy' => $answeredBy?->toString(),
            'source' => $source->value,
        ];
    }

    public function getAnswerValue(QuestionKey $key): mixed
    {
        return $this->answers[$key->value]['value'] ?? null;
    }

    public function hasAnswer(QuestionKey $key): bool
    {
        return \array_key_exists($key->value, $this->answers);
    }

    /**
     * @return list<QuestionKey> les questions du référentiel actuel qui n'ont pas encore de réponse
     */
    public function missingAnswers(): array
    {
        return array_values(array_filter(
            QuestionKey::cases(),
            fn (QuestionKey $key): bool => !$this->hasAnswer($key),
        ));
    }

    public function isComplete(): bool
    {
        return [] === $this->missingAnswers();
    }

    /**
     * @return array<string, mixed> les réponses aplaties, valeur seule (sans métadonnées), indexées par QuestionKey::value
     */
    public function answersAsMap(): array
    {
        return array_map(static fn (array $answer): mixed => $answer['value'], $this->answers);
    }

    public function isSubmitted(): bool
    {
        return AssessmentStatus::SUBMITTED === $this->status;
    }

    /**
     * Fige la liste de réponses et le résultat du moteur de scoring au moment T. Idempotent :
     * rejouer la soumission (double clic, webhook rejoué) ne réécrit pas le résultat déjà
     * enregistré, pour ne pas faire bouger silencieusement la proposition déjà produite.
     *
     * @param array<string, mixed> $scoreSnapshot
     */
    public function submit(array $scoreSnapshot): void
    {
        if ($this->isSubmitted()) {
            return;
        }

        Assert::true($this->isComplete(), 'Impossible de soumettre un questionnaire incomplet.');

        $this->status = AssessmentStatus::SUBMITTED;
        $this->submittedAt = now();
        $this->scoreSnapshot = $scoreSnapshot;
    }
}
