# Spec — Dépôt des pièces par le client après signature du DER

Statut : à implémenter (session dédiée). Rédigé après lecture du code ; les noms de classes ci-dessous existent.
Contraintes projet : voir `CLAUDE.md` (DDD/hexagonal, idempotence, audit inaltérable, `composer check` vert, tests unitaires style maison).

## 1. Constat (état actuel)

Quand le client accuse réception du DER (`AcknowledgeDerUseCase`, lien nominatif à jeton, sans compte) :
- `DerAcknowledgement::record(...)` est enregistré (preuve : SHA-256 du PDF, nom déclaré, IP, UA, énoncé).
- `ComplianceFolder::markAsDerAcknowledged()` → `DER_SIGNED`, puis **immédiatement** `markAsAwaitingClient()` → `AWAITING_CLIENT`.
- `ProvisionClientForFolderUseCase` crée le compte client (même transaction).
- `DerAcknowledgedEvent` déclenche : audit (`AuditLogDerAcknowledgedListener`), certificat (`GenerateAcknowledgementCertificateListener`), e-mails client (`SendClientConfirmationEmailListener`) et cabinet (`SendWorkspaceConfirmationEmailListener`).

**Le trou** : l'e-mail client ne contient qu'un lien vers `/login` (`app_login`), page des professionnels. Aucune demande de pièces n'est déclenchée ni expliquée, et le client ne sait ni quoi déposer ni où.

Briques existantes réutilisables :
- Pièces attendues : `ComplianceDocument::createExpected($folder, DocumentType, $isMandatory)` ; `ComplianceFolder` (l.~479) et `AddDocumentUseCase` les créent.
- Dépôt : `UploadDocumentUseCase` → `DocumentReceivedLocalEvent` → `DocumentReceivedListener` (stockage S3 async), puis OCR (`DocumentOcrProcessedEvent`).
- Types : `Domain/Compliance/Enum/DocumentType` (`ID_CARD`, `PASSPORT`, `PROOF_OF_ADDRESS`, `SOURCE_OF_FUNDS`, `RIB`, `KBIS`, …). Statuts : `Domain/Kyc/Enum/DocumentStatus` (`PENDING`, `UPLOADED`, `PROCESSING`, `VALID`, `REJECTED`, `EXPIRED`).
- Statuts dossier : `ComplianceFolderStatus` (`AWAITING_CLIENT`, `PENDING_DOCS`, `IN_REVIEW`, `NEEDS_CORRECTION`…).
- Portail client : firewall `portal` (`^/portal`), `ClientDashboardController`, templates `templates/app/client/*` (`folder_detail.html.twig`), connexion par lien magique.
- Page d'accès client publique : `/espace-client` (`website_client_access`).
- Parcours KYB séparé (`Domain/Kyc`, `/portal/kyc/*`) : hors périmètre ici, ne pas le casser.

## 2. Objectif produit

Dès que le DER est signé, le client est guidé, sans friction, vers le dépôt de ses pièces, et le CGP voit l'avancement.

Parcours cible :
1. Le client accuse réception du DER (inchangé).
2. Écran de confirmation de l'accusé : « Merci. Prochaine étape : déposez vos pièces » avec **un bouton unique** vers son espace (lien magique direct, pas de re-saisie d'e-mail).
3. E-mail de confirmation : mêmes informations + liste des pièces attendues + lien magique vers le dépôt (au lieu de `/login`).
4. Espace client : liste claire des pièces attendues (statut, obligatoire/facultatif), dépôt par glisser-déposer / appareil photo mobile, retour immédiat (reçue, en cours de lecture, à corriger).
5. Le CGP est notifié quand toutes les pièces obligatoires sont reçues ; le dossier passe en analyse.

## 3. Règles métier

- **Pièces attendues** : déterminées à l'accusé du DER selon le type de client (`FolderType` / `IndividualFolder` vs `BusinessFolder`) et le niveau de vigilance (`DiligenceLevel`). Socle particulier : pièce d'identité, justificatif de domicile ; + justificatif d'origine des fonds selon vigilance. Le cabinet peut ajouter/retirer des pièces avant envoi (`AddDocumentUseCase` existant). Ne pas inventer de liste réglementaire : la rendre configurable (constante de domaine documentée, à faire valider par le fondateur CGP).
- **Idempotence** : l'accusé rejoué (double clic, rechargement) ne doit ni recréer les pièces attendues ni renvoyer l'e-mail (déjà garanti par `acknowledgementInForce()` ; le listener de création des pièces doit être idempotent : ne créer que les types absents).
- **Statut dossier** : après accusé → `AWAITING_CLIENT` (déjà le cas). Premier dépôt → `PENDING_DOCS` tant qu'il manque une pièce obligatoire. Dernière pièce obligatoire reçue (`UPLOADED`/`VALID`) → `IN_REVIEW`. Rejet d'une pièce par le CGP → `NEEDS_CORRECTION` + e-mail client (une seule fois par rejet).
- **Relances** : si aucune pièce après J+3, puis J+7, e-mail de relance (Messenger/Scheduler existants), arrêt dès réception ou clôture du dossier. Jamais de spam : un compteur/horodatage de relance par dossier.
- **Sécurité** : accès au dossier = client rattaché (`ClientWorkspaceRelation`) ; voter dédié ; fichiers MIME/taille bornés (PDF, JPG, PNG, HEIC ; taille max à fixer, ex. 10 Mo), antivirus si déjà présent sinon noter le risque ; stockage S3 chiffré, URL privées signées (`private_url`) ; aucune pièce dans l'URL de logs.
- **RGPD** : minimisation (ne demander que les pièces utiles), durée de conservation 5 ans après fin de relation (cf. art. L.561-12 CMF déjà cité dans l'app), suppression/legal hold gérés par l'existant (`FolderLegalHoldChangedEvent`).

## 4. Traçabilité (audit inaltérable)

Nouveaux `AuditEventType` (⚠️ compléter les `match` exhaustifs `getLabel()` et `getCategory()`) :
- `COMPLIANCE_DOCUMENTS_REQUESTED` — pièces demandées au client (acteur système, payload : liste des types).
- `COMPLIANCE_DOCUMENT_UPLOADED` — dépôt par le client (type, nom, taille, SHA-256 du fichier, IP/UA).
- `COMPLIANCE_DOCUMENTS_COMPLETE` — toutes pièces obligatoires reçues.
- Rejet/validation par le CGP : réutiliser l'existant s'il y en a, sinon ajouter.
Listeners isolés `Infrastructure/Compliance/Listener/Document/AuditLog*`, `AuditLog::initiate(...)` + `save()`. Aucun audit au rejeu idempotent.

## 5. Découpage proposé (lots livrables)

- **Lot 1 — Demande de pièces à l'accusé** : listener sur `DerAcknowledgedEvent` → use case `RequestDocumentsAfterDerUseCase` (crée les pièces attendues manquantes, audit `DOCUMENTS_REQUESTED`) ; ajustement de l'e-mail client (liste des pièces + lien magique direct vers le dossier au lieu de `/login`). Tests : idempotence, types selon dossier.
- **Lot 2 — Espace client : dépôt** : écran « Vos pièces » (LiveComponent) dans `templates/app/client/folder_detail.html.twig` ; dépôt via `UploadDocumentUseCase` (vérifier qu'il est appelable côté client, sinon use case dédié avec voter) ; états visuels (bordure d'accentuation + icône, pas de fonds criards) ; accessibilité ; mobile d'abord (caméra).
- **Lot 3 — Transitions et notifications CGP** : règles de statut du §3, e-mail/notification au CGP à la complétion, badge « Pièces manquantes » dans le dashboard (`pendingDocsCount` existe).
- **Lot 4 — Relances** : commande/handler planifié, anti-doublon, arrêt automatique.
- **Lot 5 — Rejet et correction** : le CGP rejette avec motif, le client est invité à redéposer (lien magique), audit.

## 6. Critères d'acceptation (Lot 1 + 2 = MVP)

- Après un accusé de DER, le dossier contient les pièces attendues (une seule fois, même si l'accusé est rejoué).
- Le client reçoit un e-mail avec la liste des pièces et un lien magique qui l'amène directement sur la liste (pas `/login`).
- Le client dépose une pièce : statut `UPLOADED` puis traitement async (S3 + OCR), retour visuel sans rechargement.
- Chaque demande et chaque dépôt génère une entrée d'audit ; aucun doublon au rejeu.
- Un client ne voit jamais les pièces d'un autre dossier (test de voter).
- `composer check` vert, phpunit vert, tests unitaires sur : création idempotente des pièces, règle de statut, listener d'audit.

## 7. Questions ouvertes (à trancher avant de coder)

1. Liste exacte des pièces par type de client / niveau de vigilance (validation fondateur CGP).
2. Taille max et formats acceptés ; antivirus (présent ou à ajouter ?).
3. Le lien magique de l'e-mail peut-il connecter directement le client (durée de validité, usage unique) ou faut-il repasser par `/espace-client` ?
4. Cadence des relances (J+3/J+7 proposé).
5. Un dossier professionnel (`BusinessFolder`) bascule-t-il vers le parcours KYB (`/portal/kyc/*`) ou reste-t-il dans le flux compliance ?

## 8. Ops (après livraison)

`bin/console messenger:stop-workers`, `cache:clear`, `doctrine:migrations:migrate` (si nouvelles colonnes de relance), `tailwind:build`.
