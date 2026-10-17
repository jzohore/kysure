#!/bin/sh
set -e

# On s'assure qu'on lance bien l'application PHP (et pas juste un bash interactif)
if [ "$1" = 'frankenphp' ] || [ "$1" = 'php' ] || [ "$1" = 'bin/console' ]; then

    echo "🚀 [KYSURE OPS] Initialisation du conteneur..."

    # ---------------------------------------------------------
    # 1. 🔐 DÉCHIFFREMENT DES SECRETS (SOPS + Age)
    # ---------------------------------------------------------
    if [ "$APP_ENV" = 'prod' ] || [ "$APP_ENV" = 'staging' ]; then
        TARGET_ENV_FILE=".env.${APP_ENV}.enc"

        if [ -f "$TARGET_ENV_FILE" ] && [ -n "$SOPS_AGE_KEY" ]; then
            echo "🔑 [KYSURE SEC] Déchiffrement de $TARGET_ENV_FILE via SOPS..."
            sops -d "$TARGET_ENV_FILE" > .env.local
        else
            echo "⚠️ [KYSURE OPS] Fichier $TARGET_ENV_FILE ou clé SOPS manquante. Impossible de déchiffrer."
        fi
    fi

    # ---------------------------------------------------------
    # 2. 📦 INSTALLATION DES DÉPENDANCES (Dev uniquement)
    # ---------------------------------------------------------
    if [ -z "$(ls -A 'vendor/' 2>/dev/null)" ]; then
       echo "📦 [KYSURE DEV] Installation des vendors manquants..."
       composer install --prefer-dist --no-progress --no-interaction
    fi

    # ---------------------------------------------------------
    # 3. 🗄️ VÉRIFICATION ET MIGRATION DE LA BDD
    # ---------------------------------------------------------
    if grep -q ^DATABASE_URL= .env* 2>/dev/null; then
       echo '⏳ [KYSURE BDD] En attente de la connexion PostgreSQL...'
       ATTEMPTS_LEFT_TO_REACH_DATABASE=60
       until [ $ATTEMPTS_LEFT_TO_REACH_DATABASE -eq 0 ] || DATABASE_ERROR=$(php bin/console dbal:run-sql -q "SELECT 1" 2>&1); do
          if [ $? -eq 255 ]; then
             ATTEMPTS_LEFT_TO_REACH_DATABASE=0
             break
          fi
          sleep 1
          ATTEMPTS_LEFT_TO_REACH_DATABASE=$((ATTEMPTS_LEFT_TO_REACH_DATABASE - 1))
          echo "Toujours en attente... $ATTEMPTS_LEFT_TO_REACH_DATABASE tentatives restantes."
       done

       if [ $ATTEMPTS_LEFT_TO_REACH_DATABASE -eq 0 ]; then
          echo '❌ [KYSURE BDD FATAL] La base de données est injoignable :'
          echo "$DATABASE_ERROR"
          exit 1
       else
          echo '✅ [KYSURE BDD] Connexion établie.'
       fi

       # ----------------------------------------------------------
       # MIGRATIONS DOCTRINE
       #  - PROD / STAGING uniquement : en dev tu maîtrises tes migrations
       #    WIP à la main (et l'historique local peut diverger). Pour activer
       #    en dev, retire le test sur APP_ENV ci-dessous.
       #  - Conteneur WEB uniquement (KYSURE_ROLE != worker) : web et worker
       #    démarrent en parallèle (cf. compose.staging.yaml) ; sans ce garde,
       #    deux « migrate » concurrents corrompent l'historique. Le conteneur
       #    web est nommé (container_name) → il n'y en a qu'un seul.
       # ----------------------------------------------------------
       if { [ "$APP_ENV" = 'prod' ] || [ "$APP_ENV" = 'staging' ]; } \
          && [ "$KYSURE_ROLE" != 'worker' ] \
          && [ -n "$(find ./migrations -maxdepth 1 -iname 'Version*.php' -print -quit)" ]; then

          echo "📦 [KYSURE BDD] Synchronisation de la table d'historique des migrations..."
          # Idempotent : crée / met à niveau la table de suivi si besoin.
          php bin/console doctrine:migrations:sync-metadata-storage --no-interaction

          echo "🚀 [KYSURE BDD] Application des migrations en attente (transaction atomique)..."
          # --allow-no-migration : sortie 0 s'il n'y a rien de nouveau.
          # --all-or-nothing     : tout le lot dans UNE transaction → rollback
          #                        total si une requête plante (zéro schéma à moitié appliqué).
          #   ⚠️ incompatible avec une migration non transactionnelle
          #      (CREATE INDEX CONCURRENTLY, ALTER TYPE ... ADD VALUE, VACUUM) :
          #      une telle migration doit déclarer isTransactional(): false,
          #      et alors il faut retirer --all-or-nothing pour ce déploiement.
          if ! php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration --all-or-nothing; then
             echo "❌ [KYSURE BDD FATAL] Migration échouée — déploiement interrompu (Coolify va rollback)."
             echo "   Diagnostic          : bin/console doctrine:migrations:list"
             echo "   Historique désynchro (schéma déjà appliqué à la main) — marquer SANS exécuter :"
             echo "   bin/console doctrine:migrations:version --add 'DoctrineMigrations\\VersionXXXXXXXXXXXXXX' --no-interaction"
             exit 1
          fi
          echo "✅ [KYSURE BDD] Schéma à jour."

       elif [ "$KYSURE_ROLE" = 'worker' ]; then
          echo "ℹ️ [KYSURE BDD] Rôle worker : migrations pilotées par le conteneur web, étape ignorée."
       else
          echo "ℹ️ [KYSURE BDD] Migrations non exécutées ici (env dev, ou aucun fichier de migration)."
       fi
    fi

    # ---------------------------------------------------------
    # 4. 🧹 OPTIMISATION DU CACHE & WORKERS (Prod/Staging)
    # ---------------------------------------------------------
    if [ "$APP_ENV" = 'prod' ] || [ "$APP_ENV" = 'staging' ]; then
        echo "⚡ [KYSURE CACHE] Vidage et préchauffage du cache..."
        php bin/console cache:clear --no-warmup
        php bin/console cache:warmup

        echo "🔄 [KYSURE WORKER] Arrêt propre des workers Messenger..."
        php bin/console messenger:stop-workers || true
    fi

    echo '🟢 [KYSURE OPS] Initialisation terminée !'

    # ---------------------------------------------------------
    # 5. 🔀 ROUTAGE DU PROCESSUS (WEB vs WORKER)
    # ---------------------------------------------------------
    if [ "$KYSURE_ROLE" = 'worker' ]; then
       echo "⚙️ [KYSURE OPS] Démarrage en mode WORKER Messenger asynchrone..."
       # Récepteurs surchargeables par conteneur (ex. worker e-mails : MESSENGER_RECEIVERS="async_priority").
       # ⚠️ `failed` est EXCLU : avec --all, le worker rejouait les messages en échec définitif puis les
       # SUPPRIMAIT (file d'analyse vidée, e-mails rejoués, appels Textract/Gemini refacturés).
       # Rejouer un échec = décision humaine via `messenger:failed:retry`.
       RECEIVERS="${MESSENGER_RECEIVERS:---all --exclude-receivers=failed}"
       # memory_limit PHP < limite Docker : erreur PHP propre (message requeué + log) plutôt qu'un
       # OOMKill silencieux du noyau en plein message. `--memory-limit` ne contrôle qu'entre deux messages.
       # shellcheck disable=SC2086
       exec php -d memory_limit="${WORKER_PHP_MEMORY_LIMIT:-256M}" bin/console messenger:consume $RECEIVERS \
            --memory-limit=128M --time-limit=3600
    fi

    echo "🌐 [KYSURE OPS] Démarrage en mode WEB (FrankenPHP)..."
fi

# Si le rôle n'est pas "worker", on lance le processus Web par défaut de l'image
exec docker-php-entrypoint "$@"
