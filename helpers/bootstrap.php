<?php
/**
 * Bootstrap: load config (survives Git deploy) and all helpers
 */

declare(strict_types=1);

$appRoot = realpath(dirname(__DIR__)) ?: dirname(__DIR__);

/**
 * All locations we check for config (first existing file wins).
 */
function getConfigCandidatePaths(string $appRoot): array
{
    $paths = [];

    $envPath = getenv('BOMBAY_CONFIG_PATH');
    if ($envPath !== false && $envPath !== '') {
        $paths[] = $envPath;
    }

    if (!empty($_SERVER['DOCUMENT_ROOT'])) {
        $docRoot = realpath($_SERVER['DOCUMENT_ROOT']) ?: $_SERVER['DOCUMENT_ROOT'];
        $paths[] = $docRoot . '/../private/config.php';
        $paths[] = $docRoot . '/private/config.php';
    }

    $paths[] = $appRoot . '/../private/config.php';
    $paths[] = dirname($appRoot) . '/private/config.php';
    $paths[] = $appRoot . '/private/config.php';
    $paths[] = $appRoot . '/config/config.local.php';
    $paths[] = $appRoot . '/config/config.php';

    // Unique, preserve order
    $unique = [];
    foreach ($paths as $p) {
        $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $p);
        if (!in_array($normalized, $unique, true)) {
            $unique[] = $normalized;
        }
    }

    return $unique;
}

$configCandidates = getConfigCandidatePaths($appRoot);
$configLoaded = false;
$loadedFrom = '';

foreach ($configCandidates as $path) {
    if (is_file($path)) {
        require_once $path;
        $configLoaded = true;
        $loadedFrom = $path;
        break;
    }
}

if (!$configLoaded) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Configuration missing.\n\n";
    echo "App root: {$appRoot}\n\n";
    echo "Checked paths (create ONE with your credentials):\n";
    foreach ($configCandidates as $path) {
        $exists = is_file($path) ? 'FOUND' : 'missing';
        echo "  [{$exists}] {$path}\n";
    }
    echo "\nRecommended (Hostinger + Git deploy):\n";
    echo "  " . dirname($appRoot) . DIRECTORY_SEPARATOR . "private" . DIRECTORY_SEPARATOR . "config.php\n";
    echo "  (folder 'private' must be NEXT TO public_html, not inside it)\n\n";
    echo "Copy from: {$appRoot}/config/config.example.php\n";
    echo "Debug: open /check-config.php once (delete after fixing).\n";
    exit(1);
}

// Optional: log which config was loaded (not the secrets)
if (defined('APP_ENV') && APP_ENV === 'development') {
    error_log('Config loaded from: ' . $loadedFrom);
}

// Full sync (2500+ SKUs) can run 30–90 minutes — avoid CLI/cron timeout
if (php_sapi_name() === 'cli') {
    @set_time_limit(0);
    @ini_set('memory_limit', '512M');
}

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/http.php';
require_once __DIR__ . '/crm.php';
require_once __DIR__ . '/stock.php';
require_once __DIR__ . '/shopify.php';
require_once __DIR__ . '/foodpanda.php';
require_once __DIR__ . '/sync_report.php';
