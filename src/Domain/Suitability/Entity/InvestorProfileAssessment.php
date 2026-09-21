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

    /**
     * Dernière réponse enregistrée (ou création si aucune réponse encore) : sert à détecter
     * un client en pause pour la relance email ({@see self::isStalledSince()}).
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    public private(set) \DateTimeImmutable $lastActivityAt;

    /**
     * Horodatage de l'email de relance envoyé au client, le cas échéant. `null` tant qu'aucune
     * relance n'a été envoyée. Volontairement envoyée une seule fois par questionnaire (pas de
     * remise à zéro si le client répond puis se remet en pause) pour ne jamais spammer.
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public private(set) ?\DateTimeImmutable $reminderSentAt = null;

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
        $this->lastActivityAt = $this->createdAt;
        $this->slugId = $this->generate_ulid_prefixed('ipa_');
    }

    public static function create(Workspace $workspace, Client $client): self
    {
        return new self($workspace, $client);
    }

    /**
     * Copie les réponses d'un questionnaire soumis auprès d'un **autre** cabinet, pour éviter
     * au client de retaper {@see QuestionKey::cases()} depuis zéro (décision produit du
     * 2026-09-17 : « on ne change pas du tout au tout »). Chaque réponse copiée reste
     * modifiable comme n'importe quelle réponse — voir {@see AnswerSource::CROSS_WORKSPACE_PREFILL}
     * pour ce que ça implique côté confirmation. Ne préremplit jamais par-dessus une réponse
     * déjà présente (n'a de sens qu'immédiatement après {@see self::create()}, sur un
     * assessment vide).
     */
    public function prefillFrom(self $source): void
    {
        foreach ($source->answersAsMap() as $rawKey => $value) {
            $key = QuestionKey::from($rawKey);
            if ($this->hasAnswer($key)) {
                continue;
            }

            $this->recordAnswer($key, $value, AnswerSource::CROSS_WORKSPACE_PREFILL, null);
        }
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
        $this->lastActivityAt = now();
    }

    public function getAnswerValue(QuestionKey $key): mixed
    {
        return $this->answers[$key->value]['value'] ?? null;
    }

    /**
     * Vrai si cette réponse a été copiée depuis un autre cabinet et pas encore vue/confirmée
     * par le client pour CE cabinet (voir {@see self::prefillFrom()}) : sert uniquement à
     * l'affichage (bandeau d'information), jamais à une décision métier.
     */
    public function isAnswerFromPrefill(QuestionKey $key): bool
    {
        return AnswerSource::CROSS_WORKSPACE_PREFILL->value === ($this->answers[$key->value]['source'] ?? null);
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
     * Vrai si le client a déjà répondu à une part significative du questionnaire (pas juste
     * démarré) : sert de garde-fou pour ne relancer par email que les abandons "vers la fin",
     * pas les brouillons à peine ouverts. Seuil arbitraire mais assumé (70%) : ajustable si le
     * taux de complétion en usage réel montre qu'il est mal calibré.
     */
    public function isNearCompletion(): bool
    {
        return (\count($this->answers) / \count(QuestionKey::cases())) >= 0.7;
    }

    public function hasReminderBeenSent(): bool
    {
        return $this->reminderSentAt instanceof \DateTimeImmutable;
    }

    /**
     * Marque la relance comme envoyée. Idempotent par construction du côté appelant : le
     * use case ne sélectionne que les assessments avec `reminderSentAt IS NULL`.
     */
    public function markReminderSent(): void
    {
        $this->reminderSentAt = now();
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
