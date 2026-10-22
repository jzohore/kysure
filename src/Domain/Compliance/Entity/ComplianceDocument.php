<?php

declare(strict_types=1);

namespace App\Domain\Compliance\Entity;

use App\Domain\Compliance\Enum\DocumentRejectionReason;
use App\Domain\Compliance\Enum\DocumentType;
use App\Domain\Compliance\Enum\OcrAnalysisState;
use App\Domain\Kyc\Entity\Stakeholder;
use App\Domain\Kyc\Enum\DocumentStatus;
use App\Infrastructure\Trait\GenerateSlugPrefixedTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;

use function Symfony\Component\Clock\now;

use Symfony\Component\Uid\Uuid;

#[ORM\Entity()]
#[ORM\Table(name: 'compliance_documents')]
#[ORM\Index(name: 'idx_doc_client_status', columns: ['status'])]
#[ORM\UniqueConstraint(name: 'uniq_doc_docuseal_submission', columns: ['docu_seal_submission_id'])]
class ComplianceDocument
{
    use GenerateSlugPrefixedTrait;

    /** Durée de validité du lien d'accusé de réception envoyé au client (jours). */
    private const int ACKNOWLEDGEMENT_TOKEN_TTL_DAYS = 30;

    /**
     * Au-delà, une pièce sans analyse n'est plus « en cours » : les 3 essais Messenger
     * (≈ 10 s d'écart) sont largement épuisés. On l'affiche comme indisponible plutôt que
     * de laisser un « en cours » éternel (message perdu, worker arrêté).
     */
    private const int OCR_IN_PROGRESS_MAX_MINUTES = 30;

    /** Longueur max d'une précision écrite par le conseiller (motif de refus, annulation). */
    private const int REVIEW_COMMENT_MAX_LENGTH = 500;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    public private(set) ?Uuid $id = null;

    #[ORM\Column(type: Types::STRING, length: 255, unique: true)]
    public private(set) string $slugId;

    #[ORM\ManyToOne(targetEntity: Stakeholder::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    public private(set) ?Stakeholder $stakeholder = null;

    #[ORM\Column(type: 'string', enumType: DocumentStatus::class)]
    public private(set) DocumentStatus $status = DocumentStatus::PENDING;

    #[ORM\Column(length: 255, nullable: true)]
    public private(set) ?string $storagePath = null;

    /** Motif de refus tel que LU PAR LE CLIENT (libellé du motif + précision éventuelle). */
    #[ORM\Column(type: 'text', nullable: true)]
    public private(set) ?string $rejectionReason = null;

    #[ORM\Column(length: 30, nullable: true, enumType: DocumentRejectionReason::class)]
    public private(set) ?DocumentRejectionReason $rejectionReasonCode = null;

    /** Décision du conseiller sur le fichier courant (validation ou refus) : quand et par qui. */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    public private(set) ?\DateTimeImmutable $reviewedAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    public private(set) ?string $reviewedByName = null;

    /**
     * Le client a été prévenu du refus (e-mail groupé « demande de corrections »). Tant que c'est null, le refus
     * reste interne au cabinet : le conseiller peut encore l'annuler, et le portail client ne l'affiche pas.
     */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    public private(set) ?\DateTimeImmutable $correctionRequestedAt = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    public private(set) ?\DateTimeImmutable $expiresAt = null;

    /**
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    public private(set) ?array $ocrData = null;

    /**
     * Points de vigilance relevés par l'analyse automatique (OCR + contrôles
     * `DocumentValidator`). Ce ne sont que des signalements : seul un humain
     * (le CGP) décide d'un rejet. Jamais affiché au client final.
     *
     * @var list<string>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    public private(set) ?array $ocrFindings = null;

    /** Version des règles de contrôle ayant produit {@see self::$ocrFindings}. */
    #[ORM\Column(length: 20, nullable: true)]
    public private(set) ?string $ocrValidatorVersion = null;

    /**
     * L'analyse automatique a définitivement échoué (retries épuisés). Garde d'idempotence du
     * signal « analyse indisponible » : posé une seule fois, effacé dès qu'une analyse aboutit.
     */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    public private(set) ?\DateTimeImmutable $ocrUnavailableAt = null;

    /**
     * Dernière relance manuelle de l'analyse (bouton opérateur). Sert uniquement à afficher « analyse en cours »
     * après une relance sur une pièce déposée depuis longtemps ; l'ancienneté du dépôt ({@see self::$uploadedAt})
     * reste la date légale et n'est jamais modifiée.
     */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    public private(set) ?\DateTimeImmutable $ocrRequestedAt = null;

    /**
     * Date de la dernière analyse automatique ABOUTIE du fichier courant (affichée au CGP). Remise à zéro avec
     * l'analyse elle-même (nouveau fichier, relance) : elle ne date jamais des données qui ne sont plus affichées.
     */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    public private(set) ?\DateTimeImmutable $ocrAnalyzedAt = null;

    /**
     * Si le type est "OTHER", on utilise ce label pour afficher au client
     * ex: "Attestation de provenance des fonds".
     */
    #[ORM\Column(length: 255, nullable: true)]
    public ?string $customLabel = null;

    #[ORM\Column(options: ['default' => false])]
    public bool $isAskToClient = false;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $filename = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $mimeType = null;

    #[ORM\Column(nullable: true)]
    public ?int $size = null;

    #[ORM\Column(nullable: true)]
    public private(set) ?int $docuSealSubmissionId = null;

    #[ORM\Column(length: 255, nullable: true)]
    public private(set) ?string $docuSealDocumentUrl = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    public private(set) ?\DateTimeImmutable $docuSealSignedAt = null;

    /** Première consultation du DER par le client (garde d'idempotence `form.viewed`). */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    public private(set) ?\DateTimeImmutable $docuSealOpenedAt = null;

    /** Refus de signature par le client (garde d'idempotence `form.declined`). */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    public private(set) ?\DateTimeImmutable $docuSealDeclinedAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    public private(set) ?string $docuSealAuditLogUrl = null;

    #[ORM\Column(length: 255, nullable: true)]
    public private(set) ?string $docuSealSignatureUrl = null;

    #[ORM\Column(length: 255, nullable: true)]
    public private(set) ?string $docuSealRejectedReason = null;

    /**
     * Jeton d'accès nominatif à la page d'accusé de réception du DER : le client
     * n'a pas encore de compte à ce stade. Seul le SHA-256 est stocké, la valeur
     * en clair n'est jamais persistée.
     */
    #[ORM\Column(type: 'string', length: 64, unique: true, nullable: true)]
    public private(set) ?string $acknowledgementTokenHash = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    public private(set) ?\DateTimeImmutable $acknowledgementTokenExpiresAt = null;

    /** Le CGP a demandé l'envoi du DER au client (le lien part dès que le PDF est prêt). */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    public private(set) ?\DateTimeImmutable $derSendRequestedAt = null;

    /** Le lien d'accusé de réception a été envoyé au client par e-mail. */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    public private(set) ?\DateTimeImmutable $derLinkSentAt = null;

    /** Le client a refusé de reconnaître le DER (« Je ne reconnais pas ce document »). */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    public private(set) ?\DateTimeImmutable $derDeclinedAt = null;

    #[ORM\Column(type: 'text', nullable: true)]
    public private(set) ?string $derDeclineReason = null;

    /**
     * Historique des accusés de réception du DER. Au plus un « en vigueur »
     * (non révoqué) à la fois — garanti par l'index unique partiel côté BDD.
     *
     * @var Collection<int, DerAcknowledgement>
     */
    #[ORM\OneToMany(targetEntity: DerAcknowledgement::class, mappedBy: 'document')]
    public private(set) Collection $acknowledgements;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $uploadedAt = null;

    private function __construct(#[ORM\ManyToOne(targetEntity: ComplianceFolder::class, inversedBy: 'documents')]
        #[ORM\JoinColumn(nullable: false)]
        public private(set) ComplianceFolder $folder, #[ORM\Column(type: 'string', enumType: DocumentType::class)]
        public private(set) DocumentType $type, #[ORM\Column]
        public bool $isMandatory)
    {
        $this->slugId = $this->generate_ulid_prefixed('comp_doc_');
        $this->acknowledgements = new ArrayCollection();
    }

    public static function createExpected(ComplianceFolder $folder, DocumentType $type, bool $isMandatory = true): self
    {
        return new self($folder, $type, $isMandatory);
    }

    /**
     * Action métier : Le client a uploadé le fichier.
     */
    public function markAsUploaded(string $storagePath, string $filename, string $mimeType, int $size): void
    {
        $this->storagePath = $storagePath;
        $this->status = DocumentStatus::UPLOADED;
        // Nouveau fichier : la décision du conseiller portait sur l'ancien, elle ne vaut plus.
        $this->resetReview();
        // Un nouveau fichier invalide l'analyse du précédent : sans cela, le garde d'idempotence
        // de l'OCR ne relirait pas le remplaçant et le CGP verrait les données de l'ancienne pièce.
        $this->resetOcrAnalysis();
        $this->uploadedAt = now();
        $this->filename = $filename;
        $this->mimeType = $mimeType;
        $this->size = $size;

        $subject = 'la société';
        if ($this->stakeholder instanceof Stakeholder) {
            $subject = sprintf('%s %s', $this->stakeholder->firstName, $this->stakeholder->lastName);
        }

        $this->folder->saveHistory(
            'Document téléversé',
            sprintf(
                'Le document "%s" concernant %s a été ajouté au dossier.',
                $this->type->getLabel(),
                $subject
            )
        );
    }

    public function markAsPending(string $email): void
    {
        // Point d'entrée réel de la (re)génération : on bloque ici, avant que
        // GenerateDerPdfHandler ne supprime le PDF précédent.
        $this->guardNotSealed();
        $this->status = DocumentStatus::PENDING;
        $this->folder->saveHistory(
            'DER Généré',
            sprintf(
                'Le document d\'entrée en Relation a été généré automatiquement par %s.',
                $email
            )
        );
    }

    public function markAsProcessed(): void
    {
        $this->status = DocumentStatus::PROCESSING;
        $this->rejectionReason = null;
    }

    /**
     * Le stockage du fichier déposé a échoué : la pièce n'est plus « en traitement ».
     * Elle retrouve son état d'avant le dépôt, pour que le client puisse réessayer.
     */
    public function markAsUploadFailed(): void
    {
        $this->status = null !== $this->storagePath ? DocumentStatus::UPLOADED : DocumentStatus::PENDING;
    }

    /**
     * Enregistre le résultat de l'analyse automatique (OCR + contrôles). L'analyse
     * ne décide jamais d'un rejet : elle extrait les données et liste des points
     * de vigilance que le CGP tranchera. Le document reste « reçu / à vérifier »
     * ({@see DocumentStatus::UPLOADED}, jamais actionnable par le client).
     *
     * @param array<string, mixed>|null $extractedData
     * @param list<string>              $findings
     */
    public function attachOcrAnalysis(?array $extractedData, array $findings, ?string $validatorVersion = null): void
    {
        $this->ocrData = $extractedData;
        $this->ocrFindings = [] === $findings ? null : $findings;
        $this->ocrValidatorVersion = $validatorVersion;
        $this->ocrUnavailableAt = null;
        $this->ocrAnalyzedAt = now();
        // Le document reste « reçu / à vérifier » : l'analyse ne le fait pas
        // avancer, elle éclaire la revue humaine. Jamais actionnable par le client.
        $this->status = DocumentStatus::UPLOADED;
        $this->rejectionReason = null;

        $subject = $this->stakeholder instanceof Stakeholder
            ? sprintf('%s %s', $this->stakeholder->firstName, $this->stakeholder->lastName)
            : 'la société';

        $this->folder->saveHistory(
            'Analyse automatique du document',
            sprintf(
                'Le document "%s" concernant %s a été analysé automatiquement : %s. En attente de vérification par le conseiller.',
                $this->type->getLabel(),
                $subject,
                [] === $findings
                    ? 'aucun point de vigilance'
                    : sprintf('%d point(s) de vigilance', count($findings)),
            )
        );
    }

    /**
     * Relance MANUELLE de l'analyse sur le fichier courant (opérateur) : l'ancienne analyse est écartée et
     * l'état repasse « en cours » jusqu'au résultat. Refusée tant qu'une analyse est déjà en cours (un double
     * clic ne doit pas facturer deux fois l'OCR), sur une pièce non analysable, ou déjà tranchée par un humain.
     *
     * @throws \DomainException si la relance n'est pas possible
     */
    public function requestOcrRerun(): void
    {
        if (null === $this->storagePath || DocumentStatus::UPLOADED !== $this->status || !$this->type->supportsOcr()) {
            throw new \DomainException('Cette pièce ne peut pas être analysée automatiquement (aucun fichier reçu, type non analysable, ou pièce déjà traitée).');
        }

        if (OcrAnalysisState::IN_PROGRESS === $this->ocrAnalysisState()) {
            throw new \DomainException('Une analyse est déjà en cours pour cette pièce.');
        }

        $this->resetOcrAnalysis();
        $this->ocrRequestedAt = now();

        $this->folder->saveHistory(
            'Analyse automatique relancée',
            sprintf('L\'analyse automatique du document "%s" a été relancée manuellement.', $this->type->getLabel())
        );
    }

    /**
     * L'analyse automatique n'a pas pu aboutir malgré les nouvelles tentatives (service OCR
     * indisponible, limitation, erreur réseau). La pièce ne reste jamais sans signal : un point
     * de vigilance invite le CGP à la vérifier à la main. Idempotent : un échec rejoué n'écrase
     * pas le signal déjà posé.
     *
     * @return bool true si le signal vient d'être posé (false = déjà signalé, ou analyse déjà faite)
     */
    public function markOcrUnavailable(): bool
    {
        if ($this->ocrUnavailableAt instanceof \DateTimeImmutable || $this->hasOcrAnalysis()) {
            return false;
        }

        $this->ocrData = null;
        $this->ocrFindings = ['L\'analyse automatique est momentanément indisponible. Vérification manuelle requise.'];
        $this->ocrValidatorVersion = null;
        $this->ocrUnavailableAt = now();

        $this->folder->saveHistory(
            'Analyse automatique indisponible',
            sprintf('Le document "%s" n\'a pas pu être analysé automatiquement : vérification manuelle requise.', $this->type->getLabel())
        );

        return true;
    }

    /**
     * Une analyse aboutie (données lues et/ou points de vigilance) est stockée pour le fichier courant.
     * Un signal « indisponible » n'en est pas une : il laisse la porte ouverte à un rejeu.
     */
    public function hasOcrAnalysis(): bool
    {
        return !$this->ocrUnavailableAt instanceof \DateTimeImmutable
            && (null !== $this->ocrData || null !== $this->ocrFindings);
    }

    /**
     * Le fichier courant doit être envoyé à l'OCR : reçu, de type analysable, pas encore analysé
     * (ou analyse précédemment indisponible → rejeu manuel possible), et jamais tranché par un humain.
     */
    public function needsOcrAnalysis(): bool
    {
        return DocumentStatus::UPLOADED === $this->status
            && null !== $this->storagePath
            && $this->type->supportsOcr()
            && !$this->hasOcrAnalysis();
    }

    public function ocrAnalysisState(): OcrAnalysisState
    {
        if (!$this->isReceived() || DocumentType::DER === $this->type) {
            return OcrAnalysisState::NOT_APPLICABLE;
        }

        if (!$this->type->supportsOcr()) {
            return OcrAnalysisState::MANUAL_CHECK;
        }

        if ($this->hasOcrAnalysis()) {
            return OcrAnalysisState::COMPLETED;
        }

        if ($this->ocrUnavailableAt instanceof \DateTimeImmutable) {
            return OcrAnalysisState::UNAVAILABLE;
        }

        $limit = now()->modify(sprintf('-%d minutes', self::OCR_IN_PROGRESS_MAX_MINUTES));
        // La demande la plus récente : dépôt du fichier, ou relance manuelle de l'analyse.
        $requestedAt = max($this->uploadedAt, $this->ocrRequestedAt);

        return $requestedAt instanceof \DateTimeImmutable && $requestedAt >= $limit
            ? OcrAnalysisState::IN_PROGRESS
            : OcrAnalysisState::UNAVAILABLE;
    }

    private function resetOcrAnalysis(): void
    {
        $this->ocrAnalyzedAt = null;
        $this->ocrData = null;
        $this->ocrFindings = null;
        $this->ocrValidatorVersion = null;
        $this->ocrUnavailableAt = null;
    }

    public function markAsGenerated(string $pdfPath): void
    {
        $this->guardNotSealed();
        $this->status = DocumentStatus::GENERATED;
        $this->storagePath = $pdfPath;
    }

    public function markAsFailed(): void
    {
        $this->status = DocumentStatus::FAILED;
    }

    // =========================================================================
    // DÉCISION DU CONSEILLER (seul un humain valide ou refuse une pièce)
    // =========================================================================

    /** La pièce attend la décision du conseiller : un fichier reçu, pas encore tranché. */
    public function awaitsAdvisorReview(): bool
    {
        return DocumentType::DER !== $this->type && DocumentStatus::UPLOADED === $this->status && null !== $this->storagePath;
    }

    /**
     * @throws \DomainException si la pièce n'attend pas de décision
     */
    public function validateByAdvisor(string $advisorName): void
    {
        $this->guardAwaitsReview();

        $this->status = DocumentStatus::VALID;
        $this->rejectionReason = null;
        $this->rejectionReasonCode = null;
        $this->correctionRequestedAt = null;
        $this->reviewedAt = now();
        $this->reviewedByName = $advisorName;

        $this->folder->saveHistory(
            'Pièce validée',
            sprintf('Le document "%s" a été validé par %s.', $this->type->getLabel(), $advisorName)
        );
    }

    /**
     * Refus avec motif. Le refus reste interne tant que le conseiller n'a pas demandé les corrections au client
     * ({@see self::markCorrectionRequested()}) : il peut encore l'annuler sans que le client l'ait vu.
     *
     * @throws \DomainException si la pièce n'attend pas de décision, ou si une précision obligatoire manque
     */
    public function rejectByAdvisor(DocumentRejectionReason $reason, ?string $comment, string $advisorName): void
    {
        $this->guardAwaitsReview();
        $comment = $this->cleanComment($comment);

        if ($reason->requiresComment() && null === $comment) {
            throw new \DomainException('Précisez le motif du refus : il sera communiqué au client.');
        }

        $this->status = DocumentStatus::REJECTED;
        $this->rejectionReasonCode = $reason;
        $this->rejectionReason = match (true) {
            null === $comment => $reason->getLabel(),
            $reason->requiresComment() => $comment,
            default => sprintf("%s\n%s", $reason->getLabel(), $comment),
        };
        $this->correctionRequestedAt = null;
        $this->reviewedAt = now();
        $this->reviewedByName = $advisorName;
        // Une pièce refusée est, par définition, redemandée au client.
        $this->isAskToClient = true;

        $this->folder->saveHistory(
            'Pièce refusée',
            sprintf('Le document "%s" a été refusé par %s. Motif : %s', $this->type->getLabel(), $advisorName, $this->rejectionReason)
        );
    }

    /** Une décision réversible : une validation, ou un refus pas encore signalé au client. */
    public function canCancelReview(): bool
    {
        return DocumentStatus::VALID === $this->status
            || (DocumentStatus::REJECTED === $this->status && !$this->correctionRequestedAt instanceof \DateTimeImmutable);
    }

    /**
     * Annule la décision (erreur de clic, pièce à revoir) : la pièce redevient « à vérifier ». Motif obligatoire.
     *
     * @return DocumentStatus la décision annulée (VALID ou REJECTED)
     *
     * @throws \DomainException si la décision n'est plus annulable ou si le motif manque
     */
    public function cancelReview(string $reason, string $advisorName): DocumentStatus
    {
        if (!$this->canCancelReview()) {
            throw new \DomainException('Cette décision ne peut plus être annulée (pièce non tranchée, ou refus déjà signalé au client).');
        }

        $reason = $this->cleanComment($reason);
        if (null === $reason) {
            throw new \DomainException('Indiquez pourquoi vous annulez cette décision.');
        }

        $cancelled = $this->status;
        $this->status = DocumentStatus::UPLOADED;
        $this->resetReview();

        $this->folder->saveHistory(
            DocumentStatus::VALID === $cancelled ? 'Validation annulée' : 'Refus annulé',
            sprintf('La décision sur le document "%s" a été annulée par %s. Motif : %s', $this->type->getLabel(), $advisorName, $reason)
        );

        return $cancelled;
    }

    /** Refusée et pas encore signalée au client : à inclure dans la prochaine demande de corrections. */
    public function isRejectionPendingNotice(): bool
    {
        return DocumentStatus::REJECTED === $this->status && !$this->correctionRequestedAt instanceof \DateTimeImmutable;
    }

    /**
     * Le client vient d'être prévenu de ce refus. Idempotent : false si déjà signalé (jamais deux e-mails).
     */
    public function markCorrectionRequested(): bool
    {
        if (!$this->isRejectionPendingNotice()) {
            return false;
        }

        $this->correctionRequestedAt = now();

        return true;
    }

    /** Le client voit le refus (portail) seulement une fois qu'il lui a été signalé. */
    public function isRejectionVisibleToClient(): bool
    {
        return DocumentStatus::REJECTED === $this->status && $this->correctionRequestedAt instanceof \DateTimeImmutable;
    }

    private function guardAwaitsReview(): void
    {
        if (!$this->awaitsAdvisorReview()) {
            throw new \DomainException('Cette pièce n\'attend pas de décision (aucun fichier reçu, ou déjà validée / refusée).');
        }
    }

    private function resetReview(): void
    {
        $this->rejectionReason = null;
        $this->rejectionReasonCode = null;
        $this->reviewedAt = null;
        $this->reviewedByName = null;
        $this->correctionRequestedAt = null;
    }

    private function cleanComment(?string $comment): ?string
    {
        $comment = trim((string) $comment);
        if ('' === $comment) {
            return null;
        }

        return mb_substr($comment, 0, self::REVIEW_COMMENT_MAX_LENGTH);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function setExtractedData(array $data): void
    {
        $this->ocrData = $data;
    }

    public function isComplete(): bool
    {
        // Si tu stockes le nom du fichier ou son chemin,
        // le document est complet si cette propriété n'est pas nulle.
        return $this->isAskToClient || null !== $this->storagePath;
    }

    /**
     * Le fichier est effectivement stocké et disponible pour le cabinet (reçu à
     * vérifier, ou validé). Distinct de {@see self::isComplete()}, qui compte
     * aussi une pièce simplement « demandée au client ».
     */
    public function isReceived(): bool
    {
        return in_array($this->status, [DocumentStatus::UPLOADED, DocumentStatus::VALID], true);
    }

    /**
     * La pièce est attendue du client : demandée explicitement par le cabinet, ou
     * obligatoire (les dossiers acquittés avant l'automatisation de la demande n'ont
     * pas le drapeau posé, sans que le client doive en pâtir).
     */
    public function isRequestedFromClient(): bool
    {
        return DocumentType::DER !== $this->type && ($this->isAskToClient || $this->isMandatory);
    }

    /**
     * Le client doit encore agir sur cette pièce : demandée, ni reçue ni en cours de traitement.
     */
    public function isAwaitingClient(): bool
    {
        return $this->isRequestedFromClient()
            && !$this->isReceived()
            && DocumentStatus::PROCESSING !== $this->status;
    }

    /**
     * Le client peut (re)déposer un fichier sur cette pièce. Une pièce déjà
     * validée par le cabinet ou en cours de traitement n'est plus remplaçable.
     */
    public function canBeReplacedByClient(): bool
    {
        return $this->isRequestedFromClient()
            && !in_array($this->status, [DocumentStatus::PROCESSING, DocumentStatus::VALID], true);
    }

    public function setAskToClient(bool $ask): void
    {
        $this->isAskToClient = $ask;
    }

    /**
     * Un DER a-t-il été signé via l'ancien circuit DocuSeal ? Les colonnes
     * `docu_seal_*` sont conservées comme preuve légale des DER déjà signés
     * avant la bascule vers l'accusé de réception interne.
     *
     * @deprecated circuit DocuSeal retiré — cf. {@see self::hasAcknowledgementInForce()}
     */
    public function isDocuSealSigned(): bool
    {
        return $this->docuSealSignedAt instanceof \DateTimeImmutable;
    }

    /**
     * Émet un jeton d'accès à la page d'accusé de réception et retourne sa valeur
     * en clair — la seule occasion de la lire. Seul le SHA-256 est conservé.
     */
    public function issueAcknowledgementToken(): string
    {
        $this->guardNotSealed();

        $clearToken = bin2hex(random_bytes(32));
        $this->acknowledgementTokenHash = hash('sha256', $clearToken);
        $this->acknowledgementTokenExpiresAt = now()->modify(sprintf('+%d days', self::ACKNOWLEDGEMENT_TOKEN_TTL_DAYS));

        return $clearToken;
    }

    public function isAcknowledgementTokenExpired(): bool
    {
        return !$this->acknowledgementTokenExpiresAt instanceof \DateTimeImmutable
            || $this->acknowledgementTokenExpiresAt < now();
    }

    /**
     * Le CGP demande la transmission du DER au client. Le lien d'accusé de
     * réception ne partira qu'une fois le PDF généré.
     */
    public function requestAcknowledgementSend(): void
    {
        $this->guardNotSealed();
        $this->derSendRequestedAt ??= now();
    }

    public function markAcknowledgementLinkSent(): void
    {
        $this->derLinkSentAt = now();
    }

    public function isAcknowledgementSendRequested(): bool
    {
        return $this->derSendRequestedAt instanceof \DateTimeImmutable;
    }

    public function isAcknowledgementLinkSent(): bool
    {
        return $this->derLinkSentAt instanceof \DateTimeImmutable;
    }

    /**
     * Le client déclare ne pas reconnaître le DER. Idempotent : seule la
     * première déclaration est horodatée.
     */
    public function markDerDeclined(?string $reason): void
    {
        if ($this->derDeclinedAt instanceof \DateTimeImmutable) {
            return;
        }

        $reason = null !== $reason ? trim($reason) : null;
        $this->derDeclinedAt = now();
        $this->derDeclineReason = '' !== (string) $reason ? $reason : null;
    }

    public function isDerDeclined(): bool
    {
        return $this->derDeclinedAt instanceof \DateTimeImmutable;
    }

    public function hasAcknowledgementInForce(): bool
    {
        return $this->acknowledgementInForce() instanceof DerAcknowledgement;
    }

    public function acknowledgementInForce(): ?DerAcknowledgement
    {
        foreach ($this->acknowledgements as $acknowledgement) {
            if ($acknowledgement->isInForce()) {
                return $acknowledgement;
            }
        }

        return null;
    }

    /**
     * Le dernier accusé révoqué de ce DER, le cas échéant (consultation par le
     * cabinet — la preuve reste archivée après révocation).
     */
    public function lastRevokedAcknowledgement(): ?DerAcknowledgement
    {
        $lastRevoked = null;
        foreach ($this->acknowledgements as $acknowledgement) {
            if (!$acknowledgement->isRevoked()) {
                continue;
            }

            if (!$lastRevoked instanceof DerAcknowledgement || $acknowledgement->acknowledgedAt > $lastRevoked->acknowledgedAt) {
                $lastRevoked = $acknowledgement;
            }
        }

        return $lastRevoked;
    }

    /**
     * Réouvre le circuit d'accusé de réception pour un nouveau cycle : à
     * appeler après régénération d'un DER dont le précédent cycle s'est
     * terminé par une révocation ou un refus client. Sans cela, le lien
     * précédemment envoyé au client resterait considéré comme « déjà
     * envoyé » et {@see \App\Infrastructure\Compliance\Listener\DER\SendDerAcknowledgementLinkListener}
     * ne renverrait jamais de nouveau lien.
     */
    public function reopenAcknowledgementCircuit(): void
    {
        $this->derSendRequestedAt = null;
        $this->derLinkSentAt = null;
        $this->derDeclinedAt = null;
        $this->derDeclineReason = null;
    }

    /**
     * Interdit toute mutation destructive d'un DER figé (signé ou acquitté) : la
     * preuve porte sur un PDF précis ; une correction passe par une révocation
     * motivée puis régénération.
     */
    private function guardNotSealed(): void
    {
        if ($this->isDocuSealSigned()) {
            throw new \DomainException('Le DER est signé : sa régénération est interdite, passer par une révocation motivée.');
        }

        if ($this->hasAcknowledgementInForce()) {
            throw new \DomainException('Le DER a été acquitté par le client : sa régénération est interdite, passer par une révocation motivée de l\'accusé.');
        }
    }
}
