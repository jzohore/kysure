<?php

declare(strict_types=1);

/**
 * KYSURE - Environmental Sanitizer Script.
 *
 * Génère automatiquement un fichier .env anonymisé et sécurisé pour Git
 * à partir du fichier .env.local (qui contient tes véritables clés de dev).
 */
$rootDir = dirname(__DIR__);
$envLocalPath = $rootDir . '/.env.local';
$envPath = $rootDir . '/.env';

if (!file_exists($envLocalPath)) {
    echo "⚠️  [KYSURE SEC] Aucun fichier .env.local trouvé. Génération ignorée.\n";
    exit(0);
}

/**
 * Liste blanche des clés dont les VALEURS sont publiques ou structurelles
 * et peuvent être conservées telles quelles dans le repository Git.
 */
$safeValueKeys = [
    // Configuration Docker / Ports
    'HTTP_PORT',
    'HTTPS_PORT',
    'HTTP3_PORT',

    // Postgres Structure (Dev)
    'POSTGRES_VERSION',
    'POSTGRES_CHARSET',
    'POSTGRES_USER',
    'POSTGRES_DB',

    // Environment & App Settings
    'APP_ENV',
    'SERVER_NAME',
    'COMPANY_NAME',
    'DOMAIN_NAME',
    'MAILER_NAME',
    'TRUSTED_PROXIES',
    'DEFAULT_URI',
    'ROUTER_DEFAULT_URI',
    'ROUTER_REQUEST_CONTEXT_HOST',
    'ROUTER_REQUEST_CONTEXT_SCHEME',

    // Service Modes / Endpoints public infrastructure
    'OCR_MODE',
    'OPEN_SANCTIONS_MODE',
    'IS_STAGING',
    'S3_REGION',
    'S3_ENDPOINT',
    'S3_BASE_URL',
    'AWS_REGION',
    'GEOIP_DB_PATH',
    'GOTENBERG_DSN',
    'GOTENBERG_URL',
    'CADDY_MERCURE_URL',
    'CADDY_MERCURE_PUBLIC_URL',
    'MAILER_DSN',
    'LOCK_DSN',

    // KYSURE Company Defaults
    'KYSURE_NAME',
    'KYSURE_SIRET',
    'KYSURE_ADDRESS',
    'KYSURE_CONTACT_EMAIL',
    'KYSURE_CURRENT_CGV_VERSION',
];

/**
 * Mappage spécifique des valeurs génériques explicites (Dummys pour la CI/Git).
 */
$customPlaceholders = [
    'POSTGRES_PASSWORD' => 'shield_dev_password',
    'DATABASE_URL' => 'postgresql://shield_admin:shield_dev_password@database:5432/shield_app?serverVersion=18&charset=utf8',
    'MESSENGER_TRANSPORT_DSN' => 'amqp://guest:guest@rabbitmq:5672/%2f',
    'CADDY_MERCURE_JWT_SECRET' => '!ChangeThisMercureHubJWTSecretKey!',
    'APP_SECRET' => 'change_this_app_secret_in_env_local',
    'EXPECTED_USER' => 'cgp_admin',
    'EXPECTED_PASS' => 'change_me_local_pass',
    'S3_BUCKET_NAME' => 'kysure-kyc-documents-dev',
];

$lines = file($envLocalPath, \FILE_IGNORE_NEW_LINES);

// 2. 🛡️ Sécurisation stricte exigée par PHPStan :
// Si la lecture échoue (ex: problème de permissions), on stoppe tout.
if (false === $lines) {
    echo "❌ [KYSURE SEC] Erreur critique : Impossible de lire le contenu de .env.local (vérifiez les permissions).\n";
    exit(1); // Code d'erreur système pour stopper la CI ou GrumPHP
}

$sanitizedLines = [];
foreach ($lines as $line) {
    $trimmedLine = trim($line);

    // Conserver les commentaires et les lignes vides
    if ('' === $trimmedLine || str_starts_with($trimmedLine, '#')) {
        $sanitizedLines[] = $line;
        continue;
    }

    if (str_contains($line, '=')) {
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);

        if (array_key_exists($key, $customPlaceholders)) {
            // Remplacement explicite pour les DSN ou secrets connus
            $sanitizedLines[] = sprintf('%s="%s"', $key, $customPlaceholders[$key]);
        } elseif (in_array($key, $safeValueKeys, true)) {
            // Conservation des valeurs de la liste blanche
            $sanitizedLines[] = sprintf('%s=%s', $key, trim($value));
        } else {
            // Masquage automatique pour toute clé d'API, secret ou token sensible
            $sanitizedLines[] = sprintf('%s=change_me_%s', $key, strtolower($key));
        }
    } else {
        $sanitizedLines[] = $line;
    }
}

file_put_contents($envPath, implode("\n", $sanitizedLines) . "\n");

echo "🔒 [KYSURE SEC] .env anonymisé et régénéré avec succès depuis .env.local !\n";
