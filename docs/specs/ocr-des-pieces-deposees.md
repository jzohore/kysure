# Spec — OCR et contrôle automatique des pièces déposées par le client

Statut (2026-10-19) : **lots 1 et 2 livrés** (MVP), plus le signal d'échec définitif du lot 4. Restent : lot 3 (RIB, recto/verso CNI), lot 3 bis (service Python), lot 4 (consigne client si illisible, chiffrement de `ocrData`), lot 5 (décision CGP par pièce). Défauts 1, 2, 3, 5 et 7 corrigés ; défaut 4 corrigé pour `PROOF_OF_ADDRESS` et la date du K-bis (requêtes Textract) ; 6, 8 et 9 restent ouverts.
Rédigé après lecture du code ; les noms de classes ci-dessous existent.
Contraintes projet : voir `CLAUDE.md` (DDD/hexagonal, idempotence, audit inaltérable, `composer check` vert, tests unitaires style maison).
Prérequis livrés : [depose-documents-apres-der-signe.md](depose-documents-apres-der-signe.md) (lots 1-2), antivirus ClamAV, stockage S3 synchrone à la réception.

## 1. Constat (état actuel)

La chaîne OCR est **écrite mais jamais branchée** sur le dépôt client. Aujourd'hui, une pièce déposée passe `PROCESSING` → `UPLOADED` (stockée sur S3) et s'arrête là : aucune extraction, aucun point de vigilance pour le CGP.

Briques existantes :
- `Domain/Port/OcrProviderInterface::extractData(DocumentType, string $filePath): array` — lève `\DomainException` si le document est illisible / non pris en charge.
- Implémentations (`Infrastructure/Ocr/`) : `AmazonTextractProvider` (AnalyzeID pour pièces d'identité + repli CNI française + Queries pour KBIS / justificatif d'origine des fonds / liasse), `MindeeProvider`, `FakeOcrProvider`. Choix par `OcrFactory` selon `OCR_MODE` (`real` = Textract, `mindee`, sinon fake). Région AWS : `eu-west-3` (Paris).
- `Infrastructure/Ocr/Handler/ProcessOcrMessageHandler` (file `async_heavy`, 3 retries, délai 10 s) : idempotent (ne réanalyse pas si `ocrData`/`ocrFindings` présents ou statut `VALID`/`REJECTED`), appelle le provider puis `Domain/Kyc/Validator/DocumentValidator`, puis `ComplianceDocument::attachOcrAnalysis()` et `DocumentOcrProcessedEvent` (audit `KYC_DOCUMENT_OCR_PROCESSED`, acteur `system_ocr`, invisible du journal cabinet).
- **Principe déjà posé et à conserver : l'OCR ne décide jamais.** Il n'accepte ni ne rejette ; il stocke des données extraites et des « points de vigilance » que le CGP tranche.
- Affichage CGP : `templates/app/compliance/compliance_show.html.twig` (macros `render_ocr`, `render_findings`).
- Tests : `tests/Infrastructure/Ocr/Handler/ProcessOcrMessageHandlerTest`, `tests/Domain/Compliance/Entity/ComplianceDocumentOcrAnalysisTest`, `AuditLogDocumentOcrProcessedListenerTest`.

### Défauts à corriger (vérifiés dans le code)

1. **Aucun déclencheur.** `ProcessOcrMessage` n'est dispatché nulle part. `DispatchToOcrListener` écoute `Domain/Ocr/Event/OcrEvent`, qui est typé sur le `KycDocument` **historique** (parcours KYB) et n'est jamais émis (seule trace : une ligne commentée dans `TestOcrCommand`). Le point d'accroche naturel est `Infrastructure/Compliance/Listener/DocumentReceivedListener`, qui stocke désormais la pièce sur S3 dans la requête : une fois la pièce `UPLOADED`, il faut déclencher l'OCR en asynchrone (Textract lit depuis S3, pas de disque partagé).
2. **Mauvais identifiant.** `ProcessOcrMessage` porte un `documentSlugId`, mais le handler fait `ComplianceDocumentRepositoryInterface::findById()`, qui fait `find($id)` sur la clé primaire UUID. Avec un vrai slug (`comp_doc_…`) la recherche échoue. Choisir : message porteur de l'UUID, ou ajout d'un `findBySlugId` ; aligner le test du handler (qui mocke `findById` et masque le problème).
3. **Remplacement de fichier : analyse périmée.** `ComplianceDocument::markAsUploaded()` ne remet pas à zéro `ocrData` / `ocrFindings` / `ocrValidatorVersion`. Quand le client remplace une pièce, le garde d'idempotence du handler voit l'ancienne analyse et **ne relit pas** le nouveau fichier : le CGP voit les données de l'ancien document.
4. **Types sans extraction.** `buildQueriesForDocument()` ne couvre que `KBIS`, `SOURCE_OF_FUNDS`, `FINANCIAL_STATEMENTS` ; `PROOF_OF_ADDRESS`, `RIB`, `RBE`, `ARTICLES_OF_ASSOC`, `ORGANIZATION_CHART` retournent `[]`, donc finding systématique « Aucune donnée n'a pu être extraite ». Or les deux pièces du socle particulier sont `ID_CARD` et `PROOF_OF_ADDRESS`.
5. **Règles du validateur mal calibrées.**
   - Le contrôle d'identité (nom / prénom / MRZ) ne s'exécute que si `$document->stakeholder` existe (parcours KYB). Les dossiers particuliers du portail n'ont pas de stakeholder : aucun recoupement avec le nom du client alors que c'est le contrôle le plus utile.
   - La règle « date d'expiration introuvable / document expiré » s'applique à **tous** les types : un justificatif de domicile ou un K-bis n'a pas de `date_of_expiry` et recevrait un faux signalement. Les libellés de `DocumentType` annoncent « moins de 3 mois » (domicile, K-bis) : cette règle de fraîcheur n'existe pas.
   - Aucun test unitaire de `DocumentValidator` n'a été trouvé.
6. **Pièce d'identité recto/verso.** Un slot = un fichier. Une CNI en une seule photo n'a pas la MRZ (verso) → finding « MRZ absente » systématique ; seul un PDF de 2 pages donne les deux faces (le provider ne rastérise que les 2 premières pages).
7. **Échec définitif silencieux.** Après 3 retries (erreur AWS, throttling, réseau), le message part dans le transport `failed` : la pièce reste `UPLOADED` sans analyse et sans aucun signal côté CGP.
8. **Donnée sensible en clair.** `ocrData` (numéro de pièce, date et lieu de naissance, MRZ) est une colonne JSON non chiffrée. Le projet a un `EncryptionListener` (attribut `#[Encrypted]`) : à évaluer pour cette colonne.
9. **Décision CGP inexistante par pièce.** L'enum d'audit a `KYC_DOCUMENT_VALIDATED` / `KYC_DOCUMENT_REJECTED` mais aucun use case n'appelle `ComplianceDocument::reject()` ni ne pose le statut `VALID` côté Compliance. Les points de vigilance de l'OCR n'ont donc aujourd'hui aucun aboutissement. Dépendance forte : voir lot 5.

## 2. Objectif produit

Le CGP ouvre un dossier et voit, pour chaque pièce déposée, **les données lues** et **les points de vigilance** (identité différente, pièce périmée, justificatif trop ancien, photo coupée), sans avoir à tout relire. Il tranche en un clic. Le client, lui, est guidé vers un meilleur dépôt dès que l'image est inexploitable, sans qu'on le bloque.

Parcours cible :
1. Le client dépose une pièce (déjà livré) → stockée sur S3, statut `UPLOADED`.
2. L'OCR démarre en tâche de fond (quelques secondes), sans rien bloquer.
3. Le CGP voit l'analyse apparaître sur la fiche dossier (rafraîchissement temps réel via Mercure, topic `folder_<slug>`, déjà utilisé par `mercure-sync`).
4. Si l'extraction a échoué (photo illisible), une consigne non bloquante invite le client à redéposer.
5. Le CGP valide ou rejette la pièce avec motif (lot 5).

## 2 bis. Moteur d'extraction local en Python (décision d'architecture proposée)

**Idée** : ne plus tout envoyer à un service payant. Un petit service Python auto-hébergé lit les pièces simples ; Textract reste pour ce qui demande sa précision (photos de pièces d'identité). Gain : marge (pas de facturation à la page pour les PDF natifs), données qui ne quittent pas l'infra, et `OcrProviderInterface` rend le changement indolore côté PHP.

### Architecture
- **Sidecar HTTP** plutôt qu'un script appelé par le worker : le conteneur PHP (worker staging limité à 384 Mo) ne doit pas embarquer Python, Tesseract et leurs dépendances, et le service doit avoir sa propre limite mémoire. Nouveau répertoire `ocr-service/` (FastAPI + Uvicorn), image Docker dédiée, service `ocr` (dev, `compose.yaml`) et `ocr-staging` (`compose.staging.yaml`), **jamais publié** : joignable uniquement par le réseau compose.
- **Contrat** : `POST /extract` en `multipart/form-data` (`type` = valeur de `DocumentType`, `file`), en-tête `Authorization: Bearer <OCR_SERVICE_TOKEN>`. Réponse `200` JSON dans **le même format normalisé que les providers actuels** (`last_name`, `first_name`, `birth_date` en `Y-m-d`, `date_of_expiry`, `id_number`, `mrz.line_1..3`, `company_name`, `registration_number`, …) plus, pour le validateur, `document_date`, `holder_name`, `address`, `iban`. Sémantique d'erreur alignée sur le port : `422` = document illisible / non pris en charge (→ `\DomainException`, pas de retry) ; `5xx` / timeout = échec technique (→ exception réessayable par Messenger). `GET /health` pour le healthcheck.
- **Côté PHP** : `Infrastructure/Ocr/LocalOcrProvider` (Symfony HttpClient, timeout borné) et un `HybridOcrProvider` qui route par type. `OcrFactory` gagne les modes `local` (tout en local) et `hybrid` (table de routage ci-dessous) ; `real` (Textract), `mindee` et `mock` restent inchangés. Variables : `OCR_SERVICE_URL`, `OCR_SERVICE_TOKEN`.
- **Pipeline interne du service** : 1) PDF avec couche texte → extraction directe, **sans OCR** ; 2) sinon rastérisation (2 premières pages max, comme aujourd'hui) puis OCR ; 3) extracteur propre au type, qui valide ses valeurs (clés de contrôle) ; 4) JSON normalisé. Rien n'est écrit durablement : fichier en mémoire ou temporaire supprimé dans un `finally`.

### Bibliothèques envisagées (versions épinglées, licences à vérifier avant adoption)
| Besoin | Bibliothèque | Note |
|---|---|---|
| Texte d'un PDF natif | `pdfplumber` (MIT) ou `pypdfium2` (Apache/BSD) | ⚠️ **Éviter `PyMuPDF`** : licence AGPL, incompatible avec un SaaS fermé sans licence commerciale |
| OCR image / scan | `pytesseract` + binaire Tesseract avec données `fra` (Apache-2.0) | Léger (~200 Mo de RAM). `PaddleOCR` / `docTR` (Apache-2.0) = piste de phase 2 pour les photos, ~1 Go+ de RAM : ne tient pas sur le staging actuel |
| Prétraitement image | `opencv-python-headless`, `Pillow` | Redressement, niveaux de gris, binarisation, contraste |
| MRZ (CNI, passeport) | décodeur maison ou `mrz` / `PassportEye` (à vérifier) | Formats TD1 (CNI, 3 × 30) et TD3 (passeport, 2 × 44) ; **contrôle des clés ICAO 7-3-1** : une MRZ aux clés valides est fiable à coup sûr |
| Validation de valeurs | `python-stdnum` (SIREN/SIRET, IBAN), `schwifty` (IBAN/BIC) | Clé fausse = erreur de lecture, pas une donnée |
| Dates et noms | `dateparser`, `rapidfuzz` | Dates françaises (« 3 janvier 2026 »), comparaison tolérante |
| À éviter | `Surya` (poids sous licence restrictive), tout modèle vision-langage (GPU) | |

### Routage proposé en mode `hybrid`
| Type | Moteur | Pourquoi |
|---|---|---|
| `PROOF_OF_ADDRESS` | local | Majorité de PDF natifs (factures, quittances) : texte direct puis regex ; Tesseract en repli |
| `RIB` | local | IBAN validé par clé de contrôle |
| `KBIS` | local (PDF natif) puis Textract en repli | Le K-bis du greffe est un PDF natif |
| `ID_CARD`, `PASSPORT`, `RESIDENCE_PERMIT` | Textract (AnalyzeID) au MVP ; MRZ locale en **recoupement** | Photos de téléphone : la précision de Textract reste supérieure. La MRZ décodée localement sert de second avis sans coût |
| autres | aucun OCR | « Vérification manuelle » |

### Sécurité et conformité du service
- Réseau interne uniquement, jeton partagé, **aucune sortie réseau** (le service n'a pas besoin d'Internet), utilisateur non-root, système de fichiers en lecture seule hors `/tmp`, limites CPU / mémoire, taille max 10 Mo, 2 pages max, timeout par requête.
- Aucune donnée extraite dans les logs (journaliser type, durée, statut, jamais le contenu) ; pas de cache disque.
- Le fichier a déjà été analysé par ClamAV avant stockage ; le service ne reçoit que des pièces saines.
- **Données de test synthétiques uniquement** (documents générés, MRZ de démonstration ICAO) : ne jamais committer une vraie pièce d'identité.

## 3. Règles métier

- **L'OCR ne décide jamais** (principe existant). Seuls le CGP valide ou rejette ; sa décision porte un acteur et un motif tracés.
- **Idempotence / coût.** Une analyse par fichier : jamais de rappel Textract (facturé à la page) sur un fichier déjà analysé ; mais un **nouveau fichier invalide l'analyse précédente** (défaut 3). Garder le garde existant, ajouter la remise à zéro à la réception d'un nouveau fichier (méthode d'intention explicite sur l'entité, pas de setter nu).
- **Règles de contrôle par type**, à rendre déclaratives (une classe de règles par type ou une table), versionnées via `DocumentValidator::VERSION` (à incrémenter à chaque évolution). Proposition à faire valider par le fondateur CGP — ne pas inventer de seuil réglementaire :

| Type | Données lues | Points de vigilance proposés |
|---|---|---|
| `ID_CARD` / `PASSPORT` / `RESIDENCE_PERMIT` | nom, prénoms, naissance, n°, expiration, MRZ | nom / prénom ≠ identité du client du dossier ; MRZ incohérente avec la zone visuelle ; pièce expirée ; date d'expiration illisible |
| `PROOF_OF_ADDRESS` | titulaire, adresse, date du document, émetteur | titulaire ≠ client ; document de plus de 3 mois (libellé du type) ; adresse illisible |
| `KBIS` | dénomination, SIREN, date d'extrait | dénomination / SIREN ≠ dossier ; extrait de plus de 3 mois |
| `RIB` | titulaire, IBAN (masqué à l'affichage) | titulaire ≠ client ; IBAN invalide (clé de contrôle) |
| `SOURCE_OF_FUNDS`, `FINANCIAL_STATEMENTS`, `RBE`, `ARTICLES_OF_ASSOC`, `ORGANIZATION_CHART` | existant ou aucune | **pas d'OCR au MVP** : afficher « Vérification manuelle » plutôt qu'un faux signalement d'extraction ratée |

- **Référence d'identité du client** : pour un dossier particulier, nom / prénom du `IndividualFolder` (ou du `Client` rattaché) ; pour un dossier entreprise, le stakeholder ou la raison sociale. La comparaison reste tolérante (accents, tirets, distance de Levenshtein ≤ 2, déjà implémentée).
- **Échec d'extraction ≠ rejet.** Illisible → finding « vérification manuelle requise » + consigne non bloquante au client ; le statut ne change pas.
- **Échec technique définitif** (retries épuisés) : poser un finding « analyse automatique indisponible » sur la pièce et audit ; ne jamais laisser une pièce sans signal.
- **Sécurité / RGPD.** Minimisation : ne conserver dans `ocrData` que les champs utiles aux contrôles et à l'affichage (pas de dump brut Textract, IBAN et n° de pièce masqués à l'affichage). Évaluer `#[Encrypted]`. Durée de vie alignée sur la pièce (conservation 5 ans après fin de relation, art. L.561-12 CMF déjà cité dans l'app) et suppression avec elle. Les traitements Textract restent en région `eu-west-3`.

## 4. Traçabilité (audit inaltérable)

- Existant : `KYC_DOCUMENT_OCR_PROCESSED` (acteur `system_ocr`, nombre de signalements, version des règles). À conserver, sans donnée personnelle extraite dans le payload.
- À ajouter si nécessaire (⚠️ compléter les `match` exhaustifs de `AuditEventType` : `getLabel()`, `getCategory()`, `getBadgeColor()`, `isCritical()`, `isVisibleToWorkspace()`) : analyse indisponible après épuisement des retries ; invalidation d'une analyse par remplacement de fichier (peut rester dans l'historique du dossier via `ComplianceFolder::saveHistory()` si l'audit est jugé excessif — trancher avec le skill conformité).
- Décision CGP par pièce (lot 5) : réutiliser `KYC_DOCUMENT_VALIDATED` / `KYC_DOCUMENT_REJECTED` (cases existants, aujourd'hui sans émetteur) avec acteur et motif.
- Listeners isolés `Infrastructure/*/Listener/…`, `AuditLog::initiate(...)` + `save()`. Aucun audit au rejeu idempotent.

## 5. Découpage proposé (lots livrables)

- **Lot 1 — Brancher la chaîne** : émettre l'OCR après stockage (listener sur la pièce `UPLOADED`, ou fin de `DocumentReceivedListener`), corriger l'identifiant du message (défaut 2), remise à zéro de l'analyse au remplacement de fichier (défaut 3), supprimer ou migrer `OcrEvent` / `DispatchToOcrListener` (typés `KycDocument`, hors périmètre Compliance). Mercure : notifier la fiche dossier à la fin de l'analyse. Tests : déclenchement une seule fois, remplacement, rejeu idempotent.
- **Lot 2 — Règles par type** : validateur déclaratif et versionné, référence d'identité du dossier (particulier / entreprise), fraîcheur domicile / K-bis, fin des faux « expiration introuvable » ; types sans OCR → « vérification manuelle ». Tests unitaires du validateur (aujourd'hui absents), cas limites (accents, noms composés, dates absentes).
- **Lot 3 — Extraction manquante** : `PROOF_OF_ADDRESS` (priorité : fait partie du socle) puis `RIB`, `KBIS` complété (date d'extrait) ; recto/verso CNI (voir question 2). Réalisé de préférence via le lot 3 bis plutôt que par de nouvelles requêtes Textract.
- **Lot 3 bis — Service Python local** (peut démarrer en parallèle des lots 1-2, il ne touche que le contrat du port) :
  - 3 bis-a : squelette `ocr-service/` (FastAPI, `/health`, `/extract`, jeton, limites), Dockerfile, services compose dev + staging avec limite mémoire (≈ 512 Mo), healthcheck.
  - 3 bis-b : extracteurs, chacun testé hors HTTP : MRZ + clés de contrôle ; PDF natif ; `PROOF_OF_ADDRESS` (titulaire, adresse, date du document) ; `RIB` (IBAN validé) ; `KBIS` (dénomination, SIREN validé par Luhn, date d'extrait) ; repli Tesseract + prétraitement.
  - 3 bis-c : `LocalOcrProvider`, `HybridOcrProvider`, modes `local` / `hybrid` dans `OcrFactory`, variables d'environnement, test PHP avec `MockHttpClient` (200, 422, 5xx, timeout).
  - 3 bis-d : jeu de documents **synthétiques** de référence et mesure comparative local vs Textract (taux de champs corrects) avant de basculer un type en production.
- **Lot 4 — Robustesse** : échec définitif signalé (handler de message en échec ou `WorkerMessageFailedEvent` filtré sur `ProcessOcrMessage`), consigne non bloquante au client si illisible, chiffrement / masquage des données extraites.
- **Lot 5 — Décision CGP par pièce** (dépendance : sans lui les signalements n'ont pas d'issue) : valider / rejeter une pièce avec motif, statut `VALID` / `REJECTED`, e-mail au client en cas de rejet (une seule fois par rejet), transition du dossier (`NEEDS_CORRECTION`, puis `IN_REVIEW`), audit. Recoupe le lot 5 de la spec « dépôt des pièces ».

## 6. Critères d'acceptation (lots 1 + 2 = MVP)

- Un client dépose une CNI et un justificatif de domicile : au bout de quelques secondes, la fiche CGP affiche les données lues et les signalements, sans recharger la page.
- Le même fichier n'est jamais analysé deux fois (aucun second appel Textract) ; un fichier de remplacement est, lui, analysé et l'ancienne analyse disparaît.
- Une CNI au nom différent de celui du client, un justificatif de plus de 3 mois, une pièce expirée produisent chacun un signalement explicite ; un justificatif de domicile ne produit **pas** de signalement « date d'expiration introuvable ».
- Un type sans OCR affiche « Vérification manuelle », pas une erreur.
- Une indisponibilité Textract prolongée laisse un signal visible sur la pièce.
- `DocumentValidator` est couvert par des tests unitaires ; `composer check` vert, phpunit vert.
- **Lot 3 bis** : un PDF natif de justificatif de domicile est lu sans appel Textract (zéro facturation) ; une MRZ aux clés de contrôle invalides n'est jamais présentée comme une donnée sûre ; le service Python injoignable ou lent n'empêche pas le dépôt (échec réessayable puis signal sur la pièce) ; `pytest` vert sur les extracteurs et l'image se construit et démarre avec sa limite mémoire ; aucun contenu de pièce dans les logs du service.

## 7. Questions ouvertes (à trancher avant de coder)

1. Règles exactes et seuils par type (fraîcheur domicile / K-bis, tolérance de nom) : validation du fondateur CGP.
2. CNI recto/verso : un slot accepte-t-il deux fichiers (recto + verso) ou impose-t-on un PDF / une photo unique ? Impact UI du dépôt et du provider (`extractPagesAsJpeg` ne lit que 2 pages).
3. Consigne au client sur photo illisible : message non bloquant sur la ligne de la pièce, ou rien tant que le CGP n'a pas regardé ? (l'OCR ne décide pas, mais prévenir tôt évite un aller-retour.)
4. Fournisseur — **recommandation : mode `hybrid`** (voir § 2 bis) : service Python local pour les PDF natifs et les cas simples, Textract pour les photos de pièces d'identité. À confirmer : accepte-t-on d'ajouter un conteneur (≈ 512 Mo de RAM, à ajouter au budget d'un staging déjà serré avec ClamAV) ? Mindee reste en dernier recours (deux fournisseurs payants en parallèle n'ont pas d'intérêt). Critère de bascule d'un type vers le local : taux de champs corrects sur le jeu synthétique (lot 3 bis-d) au moins égal à celui de Textract.
4 bis. Phase 2 éventuelle : remplacer Tesseract par PaddleOCR / docTR pour lire les photos en local (et se passer de Textract), au prix d'un conteneur plus gros (≥ 1 Go de RAM) : à décider selon les chiffres du lot 3 bis-d.
5. Chiffrement de `ocrData` (`#[Encrypted]`) : impact sur les requêtes / l'affichage CGP.
6. Pièces d'entreprise (K-bis, statuts, RBE) : sont-elles dans le périmètre OCR du MVP ou seulement les pièces du socle particulier ?

## 8. Ops (après livraison)

- Service Python : `docker compose up -d ocr` (dev) ; en staging, `ocr-staging` dans `compose.staging.yaml` (limite mémoire, pas de port publié, `depends_on` en `service_started` pour ne pas bloquer le déploiement). Variables à définir côté web **et** worker : `OCR_MODE=hybrid`, `OCR_SERVICE_URL`, `OCR_SERVICE_TOKEN` (secret, via sops).
- Épingler les versions Python (`requirements.txt` verrouillé) et ajouter `pip-audit` à la CI en plus du `composer audit` ; l'image est ajoutée au scan Trivy existant.

- `bin/console messenger:stop-workers` (le handler et la file `async_heavy` rechargent le nouveau code), `cache:clear`, migration Doctrine si nouvelles colonnes.
- Variables : `OCR_MODE` (`real` en staging/prod), identifiants AWS Textract (`AWS_KEY`, `AWS_SECRET`, `AWS_REGION`) présents côté **worker** — c'est lui qui appelle l'API.
- Surveiller le transport `failed` (`messenger:failed:show`) ; vérifier le tarif Textract courant et fixer un plafond d'alerte (marge : une analyse par fichier, jamais de relance automatique).
- Le worker staging est limité à 384 Mo : la rastérisation PDF (Imagick, bornée à 128 Mo) doit y tenir ; à tester avec un PDF de 10 Mo.
