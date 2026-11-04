# Consignes pour l'agent « code » (dépôt jzohore/kysure)

Tu travailles sur le code. L'infrastructure (serveur Hetzner, Coolify, Traefik, DNS, secrets Coolify, ClamAV) est gérée
par un autre agent « ops » : **ne touche à aucun de ces éléments**, ils sont décrits ici pour que ton code colle à la réalité.
Tu n'as pas accès au serveur. Pousse uniquement sur `staging` (la promotion vers `master` est le workflow manuel
`promote-to-master.yml`). Ne commite jamais de secret en clair.

## 1. Contexte infra à respecter

- **Un seul serveur** (4 vCPU, 7,7 Go de RAM) héberge Coolify, Kysure staging, Kysure prod (à venir) et une autre application. Chaque service doit déclarer une limite mémoire (`deploy.resources.limits.memory`) ; indique-la dans ton compte rendu.
- **Staging** = branche `staging`, fichier `compose.staging.yaml`, déployé par Coolify à chaque push (domaine staging.kysure.fr).
- **Production** = branche `master`, fichier `compose.prod.yaml`, domaine **app.kysure.fr**, application Coolify `kysure-prod` (auto-déploiement coupé pour l'instant, le go-live est manuel).
- **Coolify injecte toutes ses variables d'environnement dans tous les services** (env_file), même celles que le compose ne cite pas. Variables déjà posées côté Coolify (tu n'as pas à les créer) :
  - staging : `VIRUS_SCAN_MODE=clamav`, `OCR_SERVICE_TOKEN` (48 car.), `POSTGRES_*`, `RABBITMQ_*`, `SOPS_AGE_KEY`, `TRUSTED_PROXIES`, `UMAMI_*`, `SERVER_NAME`, `DATABASE_URL`, `MESSENGER_TRANSPORT_DSN`.
  - prod : `VIRUS_SCAN_MODE=clamav`, `OCR_SERVICE_TOKEN` (48 car., **différent** de staging), `POSTGRES_*`, `RABBITMQ_*`, `TRUSTED_PROXIES`, `CADDY_MERCURE_JWT_SECRET`. En attente côté ops : `SOPS_AGE_KEY`, `MAILER_DSN`, `UMAMI_WEBSITE_ID`.
- **ClamAV est PARTAGÉ** entre staging et prod : un seul démon hors compose, sur le serveur. Il n'y a donc **aucun service clamav dans les compose**. Les conteneurs `php` et `worker` le joignent sous le nom `clamav` (port 3310) grâce à `extra_hosts: ["clamav:host-gateway"]`. Variables : `VIRUS_SCAN_MODE=clamav`, `CLAMAV_HOST=clamav`, `CLAMAV_PORT=3310`.
- **Traefik** possède les ports hôte 80/443 : ne publie jamais de port 80/443 (ni aucun port) sur l'hôte. Le conteneur web écoute en HTTP interne (`SERVER_NAME=:80` en prod).
- Pièges Coolify déjà rencontrés : pas de `ports: []` (liste vide) ; un service qui réutilise l'image d'un autre doit avoir son propre bloc `build:` ; ne pas mettre de `$` dans des labels ; pas de `container_name` nécessaire ; un volume Postgres change de chemin de montage en v18 (rester en 16 comme staging).

## 2. Tâches (dans cet ordre)

### Tâche A : staging, ClamAV partagé (urgent)
Dans `compose.staging.yaml` (applique **par intention**, le patch en annexe 2 peut ne pas s'appliquer tel quel si le fichier a évolué) :
1. Supprimer le service `clamav-staging`, son volume `clamav_staging_data` et la dépendance `clamav-staging` dans `depends_on` de `php-staging`.
2. `php-staging` : `CLAMAV_HOST: clamav` (au lieu de `clamav-staging`), `CLAMAV_PORT: '3310'`, et ajouter `extra_hosts: ['clamav:host-gateway']` (juste avant `logging`).
3. `worker-staging` : ajouter `VIRUS_SCAN_MODE: clamav`, `CLAMAV_HOST: clamav`, `CLAMAV_PORT: '3310'` et le même `extra_hosts`.

### Tâche B : OCR (staging puis prod)
Le secret `OCR_SERVICE_TOKEN` existe déjà dans Coolify (staging et prod). Dans les compose :
1. Le référencer **explicitement** et en obligatoire dans le service `ocr`, dans `php` et dans `worker` : `OCR_SERVICE_TOKEN: '${OCR_SERVICE_TOKEN:?}'` (le déploiement échoue franchement s'il manque).
2. Ajouter le service `ocr` à `compose.prod.yaml` (copie de la définition de staging : image/build, port interne, healthcheck, limite mémoire, même nommage sans suffixe `-staging`).
3. Dans ton compte rendu, donne la mémoire réelle attendue de `ocr` (RAM disponible aujourd'hui : environ 3,7 Go ; ClamAV partagé en consomme 1 Go).

### Tâche C : production, fichiers du dépôt
1. **`compose.prod.yaml`** : remplacer le squelette actuel (286 octets) par le fichier autonome de l'annexe 1, puis y ajouter `ocr` (tâche B). Variables obligatoires `${VAR:?}` voulues : elles font échouer le déploiement au lieu de retomber sur les valeurs par défaut de `.env`.
2. **`frankenphp/docker-entrypoint.sh`** : avec `APP_ENV=prod`, le script cherche `.env.prod.enc` alors que le fichier s'appelle `.env.production.enc` ; appliquer le patch de l'annexe 3 (prod → `.env.production.enc`).
3. **`composer.json`** : `"twig/twig": "^3.30"` (la 3.29.0 casse le rendu des formulaires avec symfony/twig-bridge 8.0.x) ; `composer update twig/twig`, commiter composer.json et composer.lock.
4. **Fusionner `master` dans `staging`** une fois (`git checkout staging && git merge origin/master`) : `master` contient le commit de fusion de la PR #26, absent de `staging`, ce qui ferait échouer la promotion en fast-forward.

### Tâche D : secrets de production (`.env.production.enc`), à faire avec l'humain qui détient la clé age
Le fichier date du 31/07 (celui de staging du 10/09), donc probablement incomplet. Il est chiffré avec la **même clé age** que staging (`.sops.yaml`) : staging peut donc déchiffrer les secrets de prod.
1. Générer une clé age dédiée prod, séparer les règles dans `.sops.yaml` (staging : clé actuelle ; production : nouvelle clé publique), re-chiffrer `.env.production.enc` avec la nouvelle clé. **La clé privée ne doit jamais entrer dans le dépôt** : l'humain la transmet à l'agent ops, qui la pose dans Coolify (`SOPS_AGE_KEY` de `kysure-prod`).
2. Contenu à contrôler/compléter : `IS_STAGING=false` et pas de `EXPECTED_USER`/`EXPECTED_PASS` ; `DEFAULT_URI`, `ROUTER_DEFAULT_URI`, `ROUTER_REQUEST_CONTEXT_HOST`, `ROUTER_REQUEST_CONTEXT_SCHEME`, `FRONTEND_URL` = `https://app.kysure.fr` (le `.env` committé contient un lien de tunnel `…lhr.life` et des `change_me_*` qui ne doivent jamais servir en prod) ; clés Stripe en live ; Mindee ; `OPEN_SANCTIONS_MODE` (mock par défaut) ; Gemini ; Sentry ; bucket S3 Scaleway (`AWS_*`, de préférence distinct de staging) ; DSN Slack ; mentions légales `KYSURE_*`.
3. **Ne pas** mettre dans ce fichier : `MAILER_DSN`, `CADDY_MERCURE_JWT_SECRET`, `VIRUS_SCAN_MODE`, `OCR_SERVICE_TOKEN` (fournis par Coolify, rendus obligatoires par le compose).

## 3. Compte rendu attendu
Liste des commits et fichiers modifiés, mémoire de chaque nouveau service, et toute variable d'environnement nouvelle que le code attend (nom, rôle, staging/prod) afin que l'agent ops la déclare dans Coolify. Dis explicitement ce que tu n'as pas pu faire.

---

## Annexe 1 : `compose.prod.yaml` autonome (à placer à la racine, puis ajouter `ocr`)

```yaml
# -----------------------------------------------------------------------------
# PRODUCTION Kysure : fichier autonome pour Coolify (derrière Traefik).
# Modèle : compose.staging.yaml, sans Mailpit ni Umami (l'analytique réutilise
# l'instance de staging) ; vrai mailer ; secrets Mercure et mailer OBLIGATOIRES
# (syntaxe ${VAR:?} : Compose refuse de démarrer s'ils manquent, plutôt que de
# retomber silencieusement sur les valeurs par défaut de .env).
#
# Variables à définir dans Coolify (application « kysure-prod ») :
#   POSTGRES_DB, POSTGRES_USER, POSTGRES_PASSWORD, RABBITMQ_USER, RABBITMQ_PASSWORD,
#   TRUSTED_PROXIES, SOPS_AGE_KEY, MAILER_DSN, CADDY_MERCURE_JWT_SECRET,
#   UMAMI_WEBSITE_ID (facultatif, vide = pas d'analytique), VIRUS_SCAN_MODE=clamav (ClamAV partagé : /opt/clamav sur le serveur).
#
# Pas de container_name : Coolify nomme les conteneurs lui-même.
# -----------------------------------------------------------------------------
x-logging: &default-logging
  driver: json-file
  options:
    max-size: "10m"
    max-file: "3"

services:
  php:
    build:
      context: .
      dockerfile: Dockerfile
      target: frankenphp_prod
    restart: unless-stopped
    environment:
      # Traefik termine le TLS et route vers php:80 en HTTP interne.
      SERVER_NAME: ':80'
      TRUSTED_PROXIES: '${TRUSTED_PROXIES:?}'
      APP_ENV: prod
      KYSURE_ROLE: web
      FRANKENPHP_NUM_THREADS: '2'
      SOPS_AGE_KEY: '${SOPS_AGE_KEY:?}'
      DATABASE_URL: 'postgresql://${POSTGRES_USER:?}:${POSTGRES_PASSWORD:?}@database:5432/${POSTGRES_DB:?}?serverVersion=16&charset=utf8'
      MESSENGER_TRANSPORT_DSN: 'amqp://${RABBITMQ_USER:?}:${RABBITMQ_PASSWORD:?}@rabbitmq:5672/%2f'
      MAILER_DSN: '${MAILER_DSN:?}'
      GOTENBERG_URL: 'http://gotenberg:3000/forms/chromium/convert/html'
      LOCK_DSN: 'flock'
      # Mercure : le hub est un module de Caddy DANS ce conteneur ; clé identique côté PHP (publication
      # et cookie d'abonnement) et côté Caddy.
      CADDY_MERCURE_JWT_SECRET: '${CADDY_MERCURE_JWT_SECRET:?}'
      MERCURE_PUBLISHER_JWT_KEY: '${CADDY_MERCURE_JWT_SECRET:?}'
      MERCURE_SUBSCRIBER_JWT_KEY: '${CADDY_MERCURE_JWT_SECRET:?}'
      # Analytique : instance Umami de staging (second site). Le <script> n'est rendu que si les deux sont non vides.
      UMAMI_SCRIPT_URL: 'https://analytics.staging.kysure.fr/script.js'
      UMAMI_WEBSITE_ID: '${UMAMI_WEBSITE_ID:-}'
      # Antivirus des pièces déposées : ClamAV partagé avec staging (extra_hosts ci-dessous).
      VIRUS_SCAN_MODE: clamav
      CLAMAV_HOST: clamav
      CLAMAV_PORT: '3310'
    # ClamAV PARTAGÉ (staging + prod) : démon unique géré hors Coolify, joignable uniquement depuis le serveur
    # sur l'adresse du pont docker0 (host-gateway). Voir /opt/clamav/compose.yaml sur le serveur.
    extra_hosts:
      - 'clamav:host-gateway'
    logging: *default-logging
    deploy:
      resources:
        limits:
          memory: 1024M
    depends_on:
      database:
        condition: service_healthy
      rabbitmq:
        condition: service_healthy

  worker:
    build:
      context: .
      dockerfile: Dockerfile
      target: frankenphp_prod
    restart: unless-stopped
    environment:
      APP_ENV: prod
      KYSURE_ROLE: worker
      SOPS_AGE_KEY: '${SOPS_AGE_KEY:?}'
      DATABASE_URL: 'postgresql://${POSTGRES_USER:?}:${POSTGRES_PASSWORD:?}@database:5432/${POSTGRES_DB:?}?serverVersion=16&charset=utf8'
      MESSENGER_TRANSPORT_DSN: 'amqp://${RABBITMQ_USER:?}:${RABBITMQ_PASSWORD:?}@rabbitmq:5672/%2f'
      MAILER_DSN: '${MAILER_DSN:?}'
      GOTENBERG_URL: 'http://gotenberg:3000/forms/chromium/convert/html'
      LOCK_DSN: 'flock'
      CADDY_MERCURE_JWT_SECRET: '${CADDY_MERCURE_JWT_SECRET:?}'
      VIRUS_SCAN_MODE: clamav
      CLAMAV_HOST: clamav
      CLAMAV_PORT: '3310'
    # ClamAV PARTAGÉ (staging + prod) : démon unique géré hors Coolify, joignable uniquement depuis le serveur
    # sur l'adresse du pont docker0 (host-gateway). Voir /opt/clamav/compose.yaml sur le serveur.
    extra_hosts:
      - 'clamav:host-gateway'
    logging: *default-logging
    deploy:
      resources:
        limits:
          memory: 384M
    depends_on:
      database:
        condition: service_healthy
      rabbitmq:
        condition: service_healthy

  database:
    image: 'postgres:16-alpine'
    restart: unless-stopped
    environment:
      POSTGRES_DB: '${POSTGRES_DB:?}'
      POSTGRES_USER: '${POSTGRES_USER:?}'
      POSTGRES_PASSWORD: '${POSTGRES_PASSWORD:?}'
    volumes:
      - 'postgres_prod_data:/var/lib/postgresql/data'
    logging: *default-logging
    deploy:
      resources:
        limits:
          memory: 1024M
    healthcheck:
      test: [ "CMD-SHELL", "pg_isready -d ${POSTGRES_DB} -U ${POSTGRES_USER}" ]
      interval: 5s
      timeout: 5s
      retries: 5

  rabbitmq:
    image: 'rabbitmq:4.3-management-alpine'
    restart: unless-stopped
    environment:
      RABBITMQ_DEFAULT_USER: '${RABBITMQ_USER:?}'
      RABBITMQ_DEFAULT_PASS: '${RABBITMQ_PASSWORD:?}'
    volumes:
      - 'rabbitmq_prod_data:/var/lib/rabbitmq'
    logging: *default-logging
    deploy:
      resources:
        limits:
          memory: 512M
    healthcheck:
      test: [ "CMD", "rabbitmq-diagnostics", "-q", "ping" ]
      interval: 10s
      timeout: 5s
      retries: 5
      start_period: 15s

  gotenberg:
    image: 'gotenberg/gotenberg:8'
    restart: unless-stopped
    logging: *default-logging
    deploy:
      resources:
        limits:
          memory: 512M

volumes:
  postgres_prod_data: null
  rabbitmq_prod_data: null
```

## Annexe 2 : patch de référence pour `compose.staging.yaml` (calculé sur le commit 2a371b6)

```diff
--- a/compose.staging.yaml
+++ b/compose.staging.yaml
@@ -45,10 +45,14 @@
       # Analytique Umami : le <script> n'est rendu que si les deux sont non vides.
       UMAMI_SCRIPT_URL: 'https://analytics.staging.kysure.fr/script.js'
       UMAMI_WEBSITE_ID: '${UMAMI_WEBSITE_ID}'
-      # Antivirus des pièces déposées par les clients (le scan se fait dans la requête web).
+      # Antivirus des pièces déposées par les clients (le scan se fait dans la requête web) : ClamAV partagé.
       VIRUS_SCAN_MODE: clamav
-      CLAMAV_HOST: clamav-staging
+      CLAMAV_HOST: clamav
       CLAMAV_PORT: '3310'
+    # ClamAV PARTAGÉ (staging + prod) : un seul démon, géré hors Coolify sur le serveur (/opt/clamav),
+    # joignable uniquement depuis le serveur sur l'adresse du pont docker0 (host-gateway) : clamav:3310.
+    extra_hosts:
+      - 'clamav:host-gateway'
     logging: *default-logging
     deploy:
       resources:
@@ -59,11 +63,6 @@
         condition: service_healthy
       rabbitmq-staging:
         condition: service_healthy
-      # service_started (pas service_healthy) : la 1re synchro des signatures prend plusieurs
-      # minutes et ne doit pas bloquer le déploiement. Tant que clamd n'est pas prêt, les
-      # dépôts de pièces sont refusés (fail-closed), le reste du site fonctionne.
-      clamav-staging:
-        condition: service_started
 
   worker-staging:
     build:
@@ -83,6 +82,13 @@
       # Le worker exécute les tâches planifiées (scheduler_cron) : c'est ici que
       # le verrou anti-concurrence protège contre une double exécution.
       LOCK_DSN: 'flock'
+      VIRUS_SCAN_MODE: clamav
+      CLAMAV_HOST: clamav
+      CLAMAV_PORT: '3310'
+    # ClamAV PARTAGÉ (staging + prod) : un seul démon, géré hors Coolify sur le serveur (/opt/clamav),
+    # joignable uniquement depuis le serveur sur l'adresse du pont docker0 (host-gateway) : clamav:3310.
+    extra_hosts:
+      - 'clamav:host-gateway'
     logging: *default-logging
     deploy:
       resources:
@@ -137,30 +143,6 @@
       retries: 5
       start_period: 15s
 
-  # Antivirus (ClamAV / clamd) : analyse chaque pièce déposée par un client avant stockage S3.
-  # Joignable en interne sur clamav-staging:3310 (jamais publié). La base de signatures est
-  # persistée (sinon re-téléchargement à chaque déploiement) et se met à jour seule (freshclam).
-  # RAM : clamd charge toutes les signatures en mémoire (~1 Go+) ; sous 1,5 Go il est tué par l'OOM.
-  clamav-staging:
-    image: 'clamav/clamav:1.4'
-    container_name: kysure_clamav_staging
-    restart: unless-stopped
-    environment:
-      CLAMD_CONF_StreamMaxLength: 25M
-    volumes:
-      - 'clamav_staging_data:/var/lib/clamav'
-    logging: *default-logging
-    deploy:
-      resources:
-        limits:
-          memory: 1536M
-    healthcheck:
-      test: [ "CMD", "/usr/local/bin/clamdcheck.sh" ]
-      interval: 30s
-      timeout: 10s
-      retries: 5
-      start_period: 180s
-
   mailpit-staging:
     image: 'axllent/mailpit:latest'
     container_name: kysure_mailpit_staging
@@ -253,4 +235,3 @@
   rabbitmq_staging_data: null
   mailpit_staging_data: null
   umami_db_staging_data: null
-  clamav_staging_data: null
```

## Annexe 3 : patch de `frankenphp/docker-entrypoint.sh`

```diff
--- a/frankenphp/docker-entrypoint.sh
+++ b/frankenphp/docker-entrypoint.sh
@@ -11,6 +11,10 @@
     # ---------------------------------------------------------
     if [ "$APP_ENV" = 'prod' ] || [ "$APP_ENV" = 'staging' ]; then
         TARGET_ENV_FILE=".env.${APP_ENV}.enc"
+        # Symfony nomme l'environnement « prod », mais le fichier de secrets s'appelle .env.production.enc.
+        if [ "$APP_ENV" = 'prod' ]; then
+            TARGET_ENV_FILE=".env.production.enc"
+        fi
 
         if [ -f "$TARGET_ENV_FILE" ] && [ -n "$SOPS_AGE_KEY" ]; then
             echo "🔑 [KYSURE SEC] Déchiffrement de $TARGET_ENV_FILE via SOPS..."
```
