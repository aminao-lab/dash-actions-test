<?php
// =============================================================================
// config.php — Configuration centrale
// Charge les variables d'environnement (.env en local, secrets en CI)
// =============================================================================

// Chargement .env local (CLI uniquement, jamais en production web)
$envFile = __DIR__ . '/../sync/.env';
$isLocal = in_array($_SERVER['SERVER_NAME'] ?? '', ['localhost', '127.0.0.1']);

if ($isLocal || PHP_SAPI === 'cli' && file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (strpos($line, '=') !== false) {
            [$name, $value] = explode('=', $line, 2);
            $name  = trim($name);
            $value = trim($value, " \t\n\r\0\x0B\"'");
            if (getenv($name) === false) {
                putenv("$name=$value");
            }
        }
    }
}

// ----------------------------------------------------------------
// Constantes Supabase
// ----------------------------------------------------------------
define('SUPABASE_URL',         getenv('SUPABASE_URL')         ?: '');
define('SUPABASE_SERVICE_KEY', getenv('SUPABASE_SERVICE_KEY') ?: '');
define('SUPABASE_ANON_KEY',    getenv('SUPABASE_ANON_KEY')    ?: '');

// ----------------------------------------------------------------
// Constantes LearnWorlds
// ----------------------------------------------------------------
define('LW_BASE_URL',   getenv('LW_BASE_URL')   ?: 'https://api.learnworlds.com');
define('LW_API_TOKEN',  getenv('LW_API_TOKEN')  ?: '');
define('LW_CLIENT_ID',  getenv('LW_CLIENT_ID')  ?: '');

// ----------------------------------------------------------------
// Sécurité session
// ----------------------------------------------------------------
define('APP_SESSION_SECRET', getenv('APP_SESSION_SECRET') ?: '');

// ----------------------------------------------------------------
// Mapping cours → niveaux
// ----------------------------------------------------------------
define('NIVEAUX', ['6eme', '5eme', '4eme', '3eme', '2nde', '1ere', 'term', 'term-pc']);

define('COURSE_MAPPING', [
    'maths-6eme'         => '6eme',
    'maths-5eme'         => '5eme',
    'maths-4eme'         => '4eme',
    'maths-3eme'         => '3eme',
    'maths-seconde'      => '2nde',
    'maths-2nde'         => '2nde',
    'maths-premiere'     => '1ere',
    'maths-1ere'         => '1ere',
    'maths-terminale'    => 'term',
    'maths-term'         => 'term',
    'maths-terminale-pc' => 'term-pc',
    'maths-term-pc'      => 'term-pc',
]);

// ----------------------------------------------------------------
// Paramètres batch
// ----------------------------------------------------------------
define('BATCH_SIZE',          100);
define('API_DELAY',           1);
define('MAX_EXECUTION_TIME',  300);
define('ACTIVE_THRESHOLD_SECONDS', 600); // 10 min minimum pour être "actif"

// ----------------------------------------------------------------
// Timezone & PHP settings
// ----------------------------------------------------------------
date_default_timezone_set('Europe/Paris');

$isCli = (PHP_SAPI === 'cli');
if ($isCli || (($_SERVER['SERVER_NAME'] ?? '') === 'localhost')) {
    ini_set('display_errors', 1);
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 0);
    error_reporting(0);
}

set_time_limit(MAX_EXECUTION_TIME);
ini_set('memory_limit', '512M');
