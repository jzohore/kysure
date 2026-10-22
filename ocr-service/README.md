# ocr-service — extraction locale des pièces

Petit service HTTP (FastAPI + Tesseract + pdfium) qui lit les pièces simples **sans les envoyer à un tiers** :
justificatif de domicile, RIB, K-bis, et MRZ des pièces d'identité. Les pièces d'identité en photo restent lues
par AWS Textract (voir le mode `hybrid`). Cadrage : `docs/specs/ocr-des-pieces-deposees.md` § 2 bis.

Côté PHP : `LocalOcrProvider` (client HTTP), `HybridOcrProvider` (routage par type), `OcrFactory` (`OCR_MODE=local|hybrid`).

## Contrat

`POST /extract` — `multipart/form-data`, en-tête `Authorization: Bearer <OCR_SERVICE_TOKEN>`

| Champ  | Valeur                                                                                         |
|--------|------------------------------------------------------------------------------------------------|
| `type` | valeur de `DocumentType` : `id_card`, `passport`, `residence_permit`, `proof_of_address`, `rib`, `kbis` |
| `file` | PDF, JPEG ou PNG (10 Mo max, **2 premières pages** lues)                                       |

| Statut | Sens                                                          | Côté PHP                              |
|--------|---------------------------------------------------------------|---------------------------------------|
| 200    | JSON normalisé (clés vides **omises** ; `{}` = rien d'exploitable) | données                          |
| 401    | jeton absent / invalide                                       | exception réessayable (config)        |
| 413    | fichier trop gros                                             | `\DomainException` (définitif)        |
| 422    | type non pris en charge, ou document illisible / corrompu     | `\DomainException` (définitif)        |
| 503    | service occupé (une extraction à la fois)                     | exception réessayable                 |
| 504    | budget de temps de la requête dépassé (45 s)                  | exception réessayable                 |
| 5xx    | erreur interne                                                | exception réessayable                 |

Le corps d'une erreur est toujours `{"error": "<code stable>"}` : jamais de texte libre, donc jamais de fragment de pièce.

`POST /render` — `multipart/form-data` avec `file` (PDF uniquement), même jeton et mêmes limites. Réponse `200`
`{"pages": ["<jpeg base64>", …]}` (2 premières pages, 200 dpi, couleur). Sert à **Textract**, qui n'accepte que des
images : le PDF d'un client est rastérisé ici (pdfium, conteneur isolé) plutôt que par Ghostscript dans le worker PHP,
dont la policy ImageMagick refuse volontairement les PDF. Aucun contenu n'est lu ni journalisé. `422` si ce n'est pas
un PDF lisible. **Conséquence : le conteneur `ocr` est requis aussi en `OCR_MODE=real` dès qu'une pièce arrive en PDF.**

Toutes les routes exigent le jeton, sauf `GET /health`.

`GET /health` — sans jeton : `200 {"status":"ok"}` si Tesseract et la langue `fra` sont disponibles, sinon `503`.

### Sortie par type (mêmes clés que les providers PHP actuels)

| Type                                    | Clés possibles                                                                                |
|-----------------------------------------|-----------------------------------------------------------------------------------------------|
| `id_card`, `passport`, `residence_permit` | `last_name`, `first_name`, `birth_date` (`Y-m-d`), `date_of_expiry`, `id_number`, `mrz.line_1..3` |
| `proof_of_address`                      | `holder_name`, `address`, `document_date` (`Y-m-d`)                                           |
| `rib`                                   | `iban`, `bic`, `holder_name`                                                                  |
| `kbis`                                  | `company_name`, `registration_number` (SIREN), `document_date` (`Y-m-d`)                       |

## Principes de fiabilité

- **Une valeur à clé de contrôle fausse n'est jamais une donnée.** MRZ : toutes les clés ICAO 9303 (7-3-1) doivent
  être valides, sinon ni `mrz` ni les champs qui en dérivent ne sont renvoyés — **la présence de `mrz` dans la réponse
  signifie donc « clés valides »**. IBAN : clé mod 97. SIREN : clé de Luhn.
- Limite connue de l'ICAO : les **noms n'ont pas de clé** propre (TD1, TD2, passeport). Le validateur PHP les recoupe
  avec la zone visuelle et avec le dossier. Exception : sur l'**ancienne CNI française**, la clé finale couvre les deux
  lignes, nom compris ; les lignes sont reconstruites (bourrage perdu par l'OCR) avant ce contrôle.
- Une date MRZ doit être entièrement numérique : `<` et `0` pèsent tous deux 0 dans la clé ICAO, la clé seule ne
  suffit donc pas à écarter un chiffre lu `<`.
- La MRZ est cherchée dans l'OCR pleine page, puis dans la bande du bas, puis sur toute la page en liste blanche
  (recto et verso photographiés ensemble : la bande est au milieu de l'image).
- Les confusions classiques de l'OCR (O/0, I/1, S/5, B/8…) sont corrigées aux seules positions où le format impose un
  chiffre, et le résultat n'est accepté que si les clés sont ensuite valides.
- PDF avec couche texte → lecture directe, **sans OCR**. Sinon rastérisation (200 dpi, niveaux de gris) puis Tesseract.
- Une date dans le futur est une erreur de lecture, pas une donnée (le validateur PHP signalera « date introuvable »).
- Les extracteurs sont des heuristiques sur du texte : ils visent la précision (champ absent plutôt que faux). Mise en
  page en colonnes ou photo de mauvaise qualité → champs manquants, jamais inventés. Le CGP tranche toujours.

## Sécurité

- Joignable **uniquement** par le réseau compose, jamais publié. Jeton Bearer comparé en temps constant ; refus du
  démarrage si `OCR_SERVICE_TOKEN` fait moins de 16 caractères (fail-closed). Le jeton est vérifié **avant** de lire
  le corps de la requête (pas d'upload de 10 Mo accepté pour un appelant non authentifié).
- Utilisateur non-root, FS en lecture seule (hors `/tmp` en tmpfs de 64 Mo), `cap_drop: ALL`, `no-new-privileges`,
  limite mémoire 512 Mo, **aucune sortie Internet** en staging (réseau `internal`).
- Rien n'est écrit sur disque : le fichier reste en mémoire le temps de la requête. Aucun cache.
- **Aucune donnée de pièce dans les logs** : seuls type, statut, taille, durée sont journalisés (testé). Les exceptions
  inattendues ne journalisent que le nom de la classe. `uvicorn --no-access-log`.
- Garde-fous : 10 Mo, 2 pages, 40 Mpx (anti « bombe de décompression »), une extraction à la fois, 45 s par requête.
- Dépendances : versions épinglées, pas de `PyMuPDF` (AGPL), pas d'`opencv`. `pip-audit` + Trivy en CI.

## Développement

```bash
docker compose up -d --build ocr                  # service de dev (interne : ocr:8000, rien n'est publié)
docker compose logs -f ocr
docker build --target test ocr-service            # pytest dans l'image (Tesseract réel inclus) — c'est ce que fait la CI
```

Utilisation depuis l'application (à poser dans `.env.local`) : `OCR_MODE=hybrid` (ou `local`).
Variables : `OCR_SERVICE_URL` (défaut `http://ocr:8000`), `OCR_SERVICE_TOKEN` (secret hors dev).

Réglages optionnels du service : `OCR_MAX_FILE_BYTES`, `OCR_MAX_PAGES`, `OCR_REQUEST_TIMEOUT`, `OCR_PAGE_TIMEOUT`.

### Ajouter un extracteur

1. Créer `app/extractors/<type>.py` avec `extract(content) -> dict` ; `content` expose `text()` (et `mrz_band_text()`
   pour les pièces d'identité). Aucune dépendance à HTTP ni à Tesseract : testable avec `FakeContent`.
2. L'enregistrer dans `EXTRACTORS` (`app/extractors/__init__.py`), clé = valeur de `DocumentType`.
3. Tests **synthétiques uniquement** (`tests/conftest.py`) : jamais une vraie pièce dans le dépôt.

## Mesures (Apple Silicon, image `python:3.12-slim`, 298 Mo)

| Cas                                                     | Durée  | Mémoire anonyme (pic) |
|---------------------------------------------------------|--------|-----------------------|
| Au repos                                                | —      | ~40 Mo                |
| Justificatif scanné (JPEG A4)                           | 0,4 s  | —                     |
| Scan A4 300 dpi dense, 2 pages (pire cas plausible)     | ~14 s  | ~230 Mo               |

(À 250 dpi le même pire cas montait à 444 Mo : la rastérisation est donc bornée à 200 dpi, en niveaux de gris.)
