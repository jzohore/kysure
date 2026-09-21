# KYSURE — contexte projet

## Le produit

SaaS **B2B** qui permet aux professionnels du patrimoine (CGP, courtiers) d'**automatiser leur
conformité réglementaire** (KYC, LCB-FT) face aux exigences **AMF** et **ACPR**.
Co-fondé par un CGP → expertise métier native, réponse aux vrais problèmes du terrain.

Valeur : gain de temps massif, zéro friction pour le client final, protection juridique.

Fonctionnalités clés :
- Édition + **signature électronique** de documents légaux type **DER** (via DocuSeal).
- Collecte de pièces justificatives via un **espace client sécurisé**.
- **OCR** des pièces (AWS Textract).
- Vérification **listes de sanctions / PPE** (Open Sanctions).
- **Entretiens** enregistrés → synthèse IA (Gemini) → rapport figé validé par le CGP.
- Facturation **Stripe**, stockage **Scaleway S3**.

La langue du projet est le **français** (commentaires, messages, termes métier). Réponds en français.

## Philosophie — impératif

**Bootstrapper** : infra frugale mais robuste. Le code priorise toujours, dans l'ordre :
1. **Sécurité des données** (données personnelles + juridiques).
2. **Idempotence** : tout webhook / listener / worker doit pouvoir rejouer sans corrompre la base
   ni spammer les utilisateurs.
3. **Traçabilité** : toute action légale ou de changement d'état (signature, validation KYC,
   validation/révocation d'un rapport…) génère un **log d'audit inaltérable**.
4. **Marge** : éviter les surcoûts techniques inutiles (pas de dépendance/infra ajoutée sans besoin réel).

Architecture **DDD + event-driven** : entités riches + Value Objects ; les actions métier émettent des
**événements** (`EventDispatcher`) écoutés par des **listeners isolés**, souvent asynchrones via
**Symfony Messenger**.

## Stack

- Symfony **8.0**, PHP **8.4** (CI tourne sur 8.5), FrankenPHP, Docker (`compose.yaml`).
- **PostgreSQL** (Doctrine ORM). Cache : pool `cache.app` **filesystem** (Redis prévu mais non activé).
- Asynchrone : Symfony **Messenger** (workers, handlers, webhooks).
- Front : Symfony UX **LiveComponent** + Twig components (`templates/components/`, style shadcn :
  `<twig:Button>`, `<twig:Dialog>`, `<twig:AlertDialog>`…), **Stimulus** (`assets/controllers/`),
  **Tailwind v4** via `symfonycasts/tailwind-bundle`, importmap (pas de build JS pour les contrôleurs).

## Architecture (hexagonale, stricte — vérifiée par deptrac)

```
src/Domain/         entités, ValueObjects, enums, events, exceptions, interfaces (Repository*, *Interface, Gateway)
src/Application/     use cases (invokables), DTO Request/Response
src/Infrastructure/  Doctrine repos (Persistence/), controllers, Twig components (Twig/Components/),
                     listeners (#[AsEventListener]), voters, clients API externes, handlers Messenger
```

- `config/services.yaml` exclut `Domain/*/{Entity,Event,ValueObject,Exception}` du conteneur.
- Résolution interface → implémentation : auto-alias si un seul implémenteur ; sinon section
  « 3. RÉSOLUTION DES INTERFACES » de `services.yaml`.
- `deptrac.yaml` fait respecter les frontières de couches.

## Conventions PHP 8.4 (suivre l'existant)

- **Typage strict absolu**, PER/PSR-12, SOLID. `Webmozart\Assert` pour les préconditions.
- **Property hooks / visibilité asymétrique** partout : `public private(set) string $x;`, `{ get => … }`.
- **Entités** : constructeur `private`/`protected` + fabrique statique (`initialize()`, `create()`,
  `validate()`…). Mutations via méthodes à intention explicite, jamais de setters nus.
- Propriétés mappées **promues dans le constructeur** avec leurs attributs `#[ORM\Column]` (rector l'impose).
- IDs : `Uuid::v7()` dans le ctor + `#[ORM\CustomIdGenerator('doctrine.uuid_generator')]`.
- Slugs : `GenerateSlugPrefixedTrait::generate_ulid_prefixed('prefix_')` (ULID base58).
- Horodatage : `Symfony\Component\Clock\now()`.
- Syntaxe `new Foo()->bar()` (sans parenthèses superflues), first-class callables `trim(...)`.

## Patterns métier

- **Exceptions** : `AbstractDomainException` (porte un `ErrorCode` + statut HTTP + payload) pour les
  erreurs remontées en HTTP ; `\DomainException` nu pour les gardes métier internes.
  ⚠️ `ErrorCode::getLabel()` est un `match` exhaustif : ajouter un case = compléter le label.
- **Audit AMF** : enum `AuditEventType` (⚠️ `match` exhaustifs dans `getLabel()` / `getCategory()`).
  Journalisation via listeners `Infrastructure/*/Listener/AuditLog*` → `AuditLog::initiate(eventName, payload, workspace)`
  → `AuditLogRepositoryInterface::save()`. Historique dossier : `ComplianceFolder::saveHistory()`
  (appelé **inline** dans le domaine Compliance ; via listener dans le domaine KYC).
- **Transactions** : `TransactionManagerInterface::transactional(callable)`. Pattern : mutations d'entité
  **avant** le bloc, appels `save()` (flush par défaut) **dans** le bloc, `dispatch()` d'event **après**.
- **Voters** : `extends Voter`, autoconfigurés. Autorisation dossier = être `WorkspaceMember` du workspace
  du dossier **et** `ComplianceFolder::canBeViewedBy()` (liste blanche de confidentialité).
- **LiveComponent** : `#[AsLiveComponent]` + `DefaultActionTrait` + `LiveFlashTrait`, actions `#[LiveAction]`
  appelant des use cases, `try { } catch (\DomainException) { $this->addFlash(...) } catch (\Throwable) { log }`.
- Un widget `position: fixed` doit être rendu **hors** d'un ancêtre animé (`animate-in` crée un containing
  block). Utiliser `{% block floating_widgets %}` de `base_billing.html.twig` (niveau `<body>`).

## UI / UX (B2B SaaS — inspiration Stripe, Linear)

- Minimaliste, épuré, professionnel. **Aucune couleur criarde en fond** : fonds `bg-white` / `slate-50`,
  bordures d'accentuation (`border-l-emerald-500`…) + icônes pour les statuts.
- Contraste accessible : texte principal `text-slate-900`, secondaire `text-slate-600`.
- **Une seule action primaire** par zone, le reste discret. Boutons via `<twig:Button>` (variantes
  `primary`/`outline`/`ghost`/`destructive`…), jamais de `bg-*` en override.
- Alignement tabulaire, scannabilité immédiate, texte d'aide inline pour poser l'enjeu.
- Accessibilité : `<label for>`, `aria-*` sur dialogues, icônes décoratives `aria-hidden="true"`,
  libellés de chargement en vrai texte, `focus-visible:ring`.

## Exploration du code — impératif

**Avant de coder**, ne pas explorer le code en lisant fichier par fichier (`grep`/`find`/lecture manuelle en
rafale) : passer par **graphify**, qui maintient un graphe du code (`src/graphify-out/graph.json`).

```bash
/Users/junior/.venvs/graphify/bin/graphify update ./src        # rafraîchit le graphe si le code a bougé
/Users/junior/.venvs/graphify/bin/graphify explain "NomDeClasse"  # voisinage d'un symbole (implémenteurs, appelants...)
/Users/junior/.venvs/graphify/bin/graphify query "question en langage naturel"  # traversée BFS ciblée
/Users/junior/.venvs/graphify/bin/graphify path "A" "B"         # chemin le plus court entre deux nœuds
```

Une fois le ou les fichiers précis identifiés via le graphe, les lire avec l'outil de lecture habituel pour
le détail d'implémentation — graphify sert à *localiser*, pas à remplacer la lecture du fichier ciblé.

## Commandes

```bash
composer format   # rector + php-cs-fixer (à lancer avant de committer)
composer check    # rector-check + cs-check + lint twig/yaml + phpstan (niveau 8, src/) + deptrac + composer audit
docker compose exec php vendor/bin/phpunit          # suite de tests
docker compose exec php bin/console tailwind:build  # après ajout de classes Tailwind arbitraires
docker compose exec php bin/console doctrine:schema:validate
```

`composer check` doit être **vert** avant de considérer une tâche terminée.

**Ops après une modif touchant l'async / la conf** : signaler les commandes à lancer en prod —
`bin/console messenger:stop-workers` (les workers rechargent le nouveau code), `cache:clear`,
`doctrine:migrations:migrate`.

## Tests

- `tests/` PSR-4 `App\Tests\`, style unitaire (`PHPUnit\Framework\TestCase`, pas de kernel/DB).
- `phpunit.dist.xml` : `failOnDeprecation` / `failOnNotice` / `failOnWarning` = **true** → tests impeccables.
  - `$this->createStub(X::class)` pour un collaborateur qu'on **stub** seulement ;
    `$this->createMock(X::class)` + `->expects()` pour une interaction **vérifiée**.
  - Pas de `->with()` sur un `->method()` sans `->expects()`. Sinon `#[AllowMockObjectsWithoutExpectations]`.
- `App\Tests\Application\ReflectionHelperTrait::createEntityState($class, [props])` : hydrate une entité
  `private(set)` sans passer par le constructeur (simule un état Doctrine).
- Classe **Fake** dédiée pour doubler un collaborateur `final` (ex. `FakeOriasChecker`).

## Pièges connus

- **ORIAS** : l'endpoint `showIntermediaire/{X}` prend un **SIREN** (9 chiffres), pas le n° d'immatriculation
  ORIAS (8 chiffres) ; renvoie du HTML si connu, un JSON `{"error":…}` sinon. Le scraping est fragile
  (sélecteurs CSS) → `OriasChecker` distingue `NOT_REGISTERED` (définitif) de `UNAVAILABLE` (à réessayer),
  et `CachedOriasChecker` ne met en cache que le définitif.
- CI **Trivy** : les CVE des binaires Go tiers (`frankenphp`, `sops`) non corrigées upstream sont filtrées
  via `.trivyignore` (avec date de revue).

## Attendu de Claude

Framer les retours comme un **lead dev** : d'abord le diagnostic (pièges invisibles cache/worker/idempotence,
stratégie métier), puis la roadmap, puis le code prêt pour la prod (typé, `Assert`, commenté en français),
puis les commandes Ops à lancer.

## Git

Branche principale : `master`. Branches de feature : `feat/...`.

**Rituel de commit (systématique)** — dès qu'un commit + push est demandé :
1. `composer format`
2. `composer check` (doit être **vert** ; sinon corriger avant d'aller plus loin)
3. `git add` + `git commit` avec un message **clair** (préfixe conventionnel : `feat:` / `fix:` / `refactor:` / `test:` / `chore:` …, description en français)
4. `git push`
